<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250110105355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add constraints and triggers for competition, tournament, championship and other entities.';
    }

    public function up(Schema $schema): void
    {
        // Adding constraints
        $this->addSql('
            ALTER TABLE competition
            ADD CONSTRAINT CHK_COMPETITION_START_BEFORE_END CHECK (start <= end);
        ');
        $this->addSql('
            ALTER TABLE tournament
            ADD CONSTRAINT CHK_TOURNAMENT_START_BEFORE_END
            CHECK (start <= end)
        ');
        $this->addSql('
            ALTER TABLE champion_ship
            ADD CONSTRAINT CHK_CHAMPIONSHIP_START_BEFORE_END
            CHECK (start <= end)
        ');

        // Constraints for time slots
        $this->addSql('
            ALTER TABLE time_slot 
            ADD CONSTRAINT CHK_TIMESLOT_ORDER 
            CHECK (start <= end);
        ');

        // Constraints for meeting
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT CHK_BLUE_SCORE_POSITIVE CHECK (blue_score >= 0)');
        $this->addSql('ALTER TABLE meeting ADD CONSTRAINT CHK_GREEN_SCORE_POSITIVE CHECK (green_score >= 0)');
        $this->addSql('
            ALTER TABLE meeting
            ADD CONSTRAINT CHK_TEAMS_DIFFERENT CHECK (blue_team_id <> green_team_id)
        ');

        $this->addSql('
            ALTER TABLE meeting
            ADD CONSTRAINT CHK_ONE_CHAMPIONSHIP_OR_TOURNAMENT
            CHECK ( (champion_ship_id IS NOT NULL AND tournament_id IS NULL)
                OR (champion_ship_id IS NULL AND tournament_id IS NOT NULL) )
        ');

        // Ensure the validity of the role
        $this->addSql("
            ALTER TABLE user
            ADD CONSTRAINT CHK_ROLES CHECK (
                roles LIKE '%ROLE_ADMIN%'
                OR roles LIKE '%ROLE_ORGA%'
                OR roles LIKE '%ROLE_USER%'
            )
        ");

        // Ensure that the meeting has a valid state
        $this->addSql('
            ALTER TABLE meeting
            ADD CONSTRAINT CHK_MEETING_STATE CHECK (
                state IN ("TO_PLAY", "PLAYED", "GAVE_UP_BLUE","GAVE_UP_GREEN","CANCELLED")
            )
        ');

        // Ensure that the team has a valid state
        $this->addSql('
            ALTER TABLE team
            ADD CONSTRAINT CHK_TEAM_STATE CHECK (
                state IN ("WAITING", "ACCEPTED", "REFUSED")
            )
        ');


        // Triggers

        // Ensure the end of championship equals the start of the tournament
        $this->addSql('
            CREATE TRIGGER TRG_CHAMPIONSHIP_END_TOURNAMENT_START
            BEFORE INSERT ON competition
            FOR EACH ROW
            BEGIN
                DECLARE champ_end_date DATE;
                DECLARE tour_start_date DATE;

                SELECT champion_ship.end
                INTO champ_end_date
                FROM champion_ship
                WHERE champion_ship.id = NEW.champion_ship_id;

                SELECT tournament.start
                INTO tour_start_date
                FROM tournament
                WHERE tournament.id = NEW.tournament_id;

                IF NOT (champ_end_date IS NULL AND tour_start_date IS NULL) AND champ_end_date != tour_start_date THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "The championship end date must be the same as the tournament start date, unless both are NULL.";
                END IF;
            END
        ');

        // Prevent competition overlap other
        $this->addSql('
            CREATE TRIGGER TRG_COMPETITION_DATES
            BEFORE INSERT ON competition
            FOR EACH ROW
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM competition c
                    WHERE (
                        (NEW.start BETWEEN c.start AND c.end 
                        OR NEW.end BETWEEN c.start AND c.end)
                        OR (NEW.start <= c.start AND NEW.end >= c.end)
                    )
                    AND c.id != NEW.id
                ) THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "The competition dates overlap with an existing competition";
                END IF;
            END
        ');

        // Trigger to prevent overlapping meetings for the same stage
        $this->addSql('
            CREATE TRIGGER TRG_OVERLAP_MEETING
            BEFORE INSERT ON meeting
            FOR EACH ROW
            BEGIN
                IF NEW.stage_id IS NOT NULL AND EXISTS (
                    SELECT 1
                    FROM meeting m
                    JOIN time_slot ts_existing ON ts_existing.id = m.time_slot_id
                    JOIN time_slot ts_new ON ts_new.id = NEW.time_slot_id
                    WHERE m.stage_id = NEW.stage_id
                    AND m.stage_id IS NOT NULL
                    AND (
                        (ts_new.start < ts_existing.end AND ts_new.end > ts_existing.start)
                        OR (ts_new.start <= ts_existing.start AND ts_new.end >= ts_existing.end)
                        OR (ts_existing.start <= ts_new.start AND ts_existing.end >= ts_new.end)
                    )
                ) THEN
                    SIGNAL SQLSTATE "45000"
                    SET MESSAGE_TEXT = "A meeting already exists for this stage at the specified time slot.";
                END IF;
            END;
        ');

        // Ensure the organiser has the ROLE_USER role
        $this->addSql('
            CREATE TRIGGER TRG_CHCK_OWNER_ROLE
            BEFORE INSERT ON team
            FOR EACH ROW
            BEGIN
                DECLARE user_roles JSON;

                SELECT roles INTO user_roles
                FROM user
                WHERE id = NEW.owner_id;

                IF JSON_CONTAINS(user_roles, \'"ROLE_USER"\') = 0 THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'The owner must have the ROLE_USER role.\';
                END IF;
            END
        ');

        // Ensure the organiser has the ROLE_ORGA role
        $this->addSql('
            CREATE TRIGGER TRG_CHECK_ORGANIZER_ROLE
            BEFORE INSERT ON competition
            FOR EACH ROW
            BEGIN
                DECLARE user_roles JSON;
                SELECT roles INTO user_roles
                FROM user
                WHERE id = NEW.organizer_id;

                IF JSON_CONTAINS(user_roles, \'"ROLE_ORGA"\') = 0 THEN
                    SIGNAL SQLSTATE \'45000\'
                    SET MESSAGE_TEXT = \'The organizer must have the ROLE_ORGA role\';
                END IF;
            END
        ');

        // Views

        $this->addSql('
            CREATE VIEW team_scores AS
            WITH RECURSIVE forfeit_adjustments AS (
                SELECT 
                    m.blue_team_id,
                    m.green_team_id,
                    m.state,
                    CASE 
                        WHEN m.state = \'GAVE_UP_BLUE\' THEN -1
                        ELSE 0 
                    END as blue_forfeit_points,
                    CASE 
                        WHEN m.state = \'GAVE_UP_GREEN\' THEN -1
                        ELSE 0 
                    END as green_forfeit_points,
                    CASE 
                        WHEN m.state = \'GAVE_UP_BLUE\' THEN 
                            ROUND(
                                ((SELECT COUNT(*) FROM meeting m2 WHERE 
                                    (m2.green_team_id = m.green_team_id OR m2.blue_team_id = m.green_team_id) 
                                    AND m2.state = \'PLAYED\'
                                ) + 1) / 
                                IF((SELECT COUNT(*) FROM meeting m2 WHERE 
                                    (m2.green_team_id = m.green_team_id OR m2.blue_team_id = m.green_team_id) 
                                    AND m2.state = \'PLAYED\') = 0, 1,
                                    (SELECT COUNT(*) FROM meeting m2 WHERE 
                                    (m2.green_team_id = m.green_team_id OR m2.blue_team_id = m.green_team_id) 
                                    AND m2.state = \'PLAYED\'))
                            )
                        WHEN m.state = \'GAVE_UP_GREEN\' THEN 
                            ROUND(
                                ((SELECT COUNT(*) FROM meeting m2 WHERE 
                                    (m2.green_team_id = m.blue_team_id OR m2.blue_team_id = m.blue_team_id) 
                                    AND m2.state = \'PLAYED\'
                                ) + 1) /
                                IF((SELECT COUNT(*) FROM meeting m2 WHERE 
                                    (m2.green_team_id = m.blue_team_id OR m2.blue_team_id = m.blue_team_id) 
                                    AND m2.state = \'PLAYED\') = 0, 1,
                                    (SELECT COUNT(*) FROM meeting m2 WHERE 
                                    (m2.green_team_id = m.blue_team_id OR m2.blue_team_id = m.blue_team_id) 
                                    AND m2.state = \'PLAYED\'))
                            )
                        ELSE 1
                    END as bonus_multiplier
                FROM meeting m
                WHERE m.state IN (\'GAVE_UP_BLUE\', \'GAVE_UP_GREEN\')
            )
            SELECT
                t.id AS team_id,
                t.name AS team_name,
                COUNT(m.id) AS matches_played,
                SUM(CASE
                    WHEN (m.blue_team_id = t.id AND m.blue_score > m.green_score) OR 
                        (m.green_team_id = t.id AND m.green_score > m.blue_score) THEN 1
                    ELSE 0
                END) AS total_wins,
                SUM(CASE
                    WHEN m.blue_score = m.green_score THEN 1
                    ELSE 0
                END) AS total_draws,
                SUM(CASE
                    WHEN (m.blue_team_id = t.id AND m.blue_score < m.green_score) OR 
                        (m.green_team_id = t.id AND m.green_score < m.blue_score) THEN 1
                    ELSE 0
                END) AS total_losses,
                SUM(CASE WHEN m.blue_team_id = t.id THEN m.blue_score ELSE 0 END) +
                SUM(CASE WHEN m.green_team_id = t.id THEN m.green_score ELSE 0 END) AS goals_scored,
                SUM(CASE WHEN m.blue_team_id = t.id THEN m.green_score ELSE 0 END) +
                SUM(CASE WHEN m.green_team_id = t.id THEN m.blue_score ELSE 0 END) AS goals_conceded,
                SUM(CASE WHEN m.blue_team_id = t.id THEN m.blue_score ELSE 0 END) +
                SUM(CASE WHEN m.green_team_id = t.id THEN m.green_score ELSE 0 END) -
                SUM(CASE WHEN m.blue_team_id = t.id THEN m.green_score ELSE 0 END) -
                SUM(CASE WHEN m.green_team_id = t.id THEN m.blue_score ELSE 0 END) AS goal_difference,
                ROUND(
                    (SUM(CASE
                        WHEN (m.blue_team_id = t.id AND m.blue_score > m.green_score) OR 
                            (m.green_team_id = t.id AND m.green_score > m.blue_score) THEN 3
                        WHEN m.blue_score = m.green_score THEN 1
                        ELSE 0
                    END) +
                    IFNULL(SUM(
                        CASE 
                            WHEN fa.blue_team_id = t.id THEN fa.blue_forfeit_points
                            WHEN fa.green_team_id = t.id THEN fa.green_forfeit_points
                            ELSE 0
                        END
                    ), 0)) *
                    IFNULL(
                        MAX(
                            CASE 
                                WHEN fa.blue_team_id = t.id OR fa.green_team_id = t.id 
                                THEN fa.bonus_multiplier 
                                ELSE 1 
                            END
                        ), 
                        1
                    )
                ) AS total_score,
                t.creation_date
            FROM team t
            LEFT JOIN meeting m ON (m.blue_team_id = t.id OR m.green_team_id = t.id) 
                AND (m.state = \'PLAYED\' OR m.state IN (\'GAVE_UP_BLUE\', \'GAVE_UP_GREEN\')) -- Inclure les GAVE_UP dans le calcul
            LEFT JOIN forfeit_adjustments fa ON (fa.blue_team_id = t.id OR fa.green_team_id = t.id)
            GROUP BY t.id
            ORDER BY total_score DESC, goal_difference DESC, t.creation_date
        ');

        // Procedures

        $this->addSql('DROP PROCEDURE IF EXISTS ClearDatabase');
        $this->addSql('
            CREATE PROCEDURE ClearDatabase()
            BEGIN
                SET FOREIGN_KEY_CHECKS = 0;
                DELETE FROM user;
                DELETE FROM champion_ship;
                DELETE FROM tournament;
                DELETE FROM team;
                DELETE FROM time_slot;
                DELETE FROM stage;
                DELETE FROM meeting;
                DELETE FROM competition;
                SET FOREIGN_KEY_CHECKS = 1;
            END
        ');

        // InitializeTestData procedure
        $this->addSql('DROP PROCEDURE IF EXISTS InitializeTestData');
        $this->addSql('
            CREATE PROCEDURE InitializeTestData()
            BEGIN
                INSERT INTO user (id, email, first_name, last_name, roles, password, creation_date)
                VALUES (999, "user999@example.com", "User", "999", \'["ROLE_ORGA"]\', "password", NOW()),
                       (998, "user998@example.com", "User", "998", \'["ROLE_USER"]\', "password", NOW());

                INSERT INTO champion_ship (id, organizer_id, name, start)
                VALUES (998, 999, "Test Championship 998", NOW());

                INSERT INTO tournament (id, name, start, end, lap)
                VALUES (997, "Test Tournament 997", NOW() - INTERVAL 1 DAY, NOW(), 1);

                INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description) 
                VALUES (999, 998, 997, 999, "Test Competition", CURDATE(), CURDATE() + INTERVAL 1 DAY, "Description");

                INSERT INTO team (id, competition_id, owner_id, name, structure, creation_date, state)
                VALUES (999, 999, 998, "Test Team 996", "Structure", NOW(), "WAITING");

                INSERT INTO time_slot (id, name, start, end)
                VALUES (995, "Test Time Slot 995", NOW(), NOW() + INTERVAL 1 HOUR);

                INSERT INTO stage (id, name)
                VALUES (994, "Test Stage 994");
            END
        ');

        // RunAllTests procedure
        $this->addSql('DROP PROCEDURE IF EXISTS RunAllTests');
        $this->addSql('
            CREATE PROCEDURE RunAllTests()
            BEGIN
                CALL ClearDatabase();
                CALL InitializeTestData();
                
                CALL TestUniqueConstraintChampionShip();
                CALL TestUniqueConstraintCompetition();
                CALL TestForeignKeyConstraintChampionShip();
                CALL TestForeignKeyConstraintCompetition();
                CALL TestTriggerChampionshipEndTournamentStart();
                CALL TestTriggerCheckOrganizerRole();
                CALL TestTriggerCompetitionDates();
                CALL TestTriggerOverlapMeeting();
                CALL TestTriggerCheckOwnerRole();
                CALL TestCheckConstraintChampionShip();
                CALL TestCheckConstraintCompetition();
                CALL TestCheckConstraintTimeSlot();
                CALL TestCheckConstraintBlueScore();
                CALL TestCheckConstraintGreenScore();
                CALL TestCheckConstraintTeamsDifferent();
                CALL TestCheckConstraintOneChampionshipOrTournament();
                CALL TestCheckConstraintRoles();
                CALL TestCheckConstraintMeetingState();
                CALL TestCheckConstraintTeamState();
            END
        ');

        // Test procedures
        $this->addTestProcedures();
    }

    private function addTestProcedures(): void
    {
        // TestUniqueConstraintChampionShip
        $this->addSql('DROP PROCEDURE IF EXISTS TestUniqueConstraintChampionShip');
        $this->addSql('
            CREATE PROCEDURE TestUniqueConstraintChampionShip()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO champion_ship (id, organizer_id, name, start) VALUES (2, 999, "Unique Championship", NOW());
                INSERT INTO champion_ship (id, organizer_id, name, start) VALUES (3, 999, "Unique Championship", NOW());
                SELECT "Unique constraint test for champion_ship failed" AS Result;
            END
        ');

        // TestUniqueConstraintCompetition
        $this->addSql('DROP PROCEDURE IF EXISTS TestUniqueConstraintCompetition');
        $this->addSql('
            CREATE PROCEDURE TestUniqueConstraintCompetition()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
                VALUES (1, 998, 997, 999, "Unique Competition", CURDATE(), CURDATE(), "Description");
                INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
                VALUES (2, 998, 997, 999, "Unique Competition", CURDATE(), CURDATE(), "Description");
                SELECT "Unique constraint test for competition failed" AS Result;
            END
        ');

        // TestCheckConstraintCompetition
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintCompetition');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintCompetition()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " " , @text) AS Result;
                END;

                INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
                VALUES (7, 998, 997, 999, "Check Constraint Test", CURDATE() + INTERVAL 1 DAY, CURDATE(), "Description"); 
                SELECT "Check constraint test for competition failed" AS Result;
            END
        ');

        // TestTriggerOverlapMeeting
        $this->addSql('DROP PROCEDURE IF EXISTS TestTriggerOverlapMeeting');
        $this->addSql('
            CREATE PROCEDURE TestTriggerOverlapMeeting()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (1, 996, 996, 995, 997, 998, 994, NULL, NULL, "PLAYED", NULL);
                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (2, 996, 996, 995, 997, 998, 994, NULL, NULL, "PLAYED", NULL); 
                SELECT "Trigger test for TRG_OVERLAP_MEETING failed" AS Result;
            END
        ');

        // TestCheckConstraintBlueScore
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintBlueScore');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintBlueScore()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (3, 996, 996, 995, 997, 998, 994, -1, NULL, "PLAYED", NULL);
                SELECT "Check constraint test for blue_score failed" AS Result;
            END
        ');

        // TestCheckConstraintGreenScore
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintGreenScore');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintGreenScore()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (4, 996, 996, 995, 997, 998, 994, NULL, -1, "PLAYED", NULL);
                SELECT "Check constraint test for green_score failed" AS Result;
            END
        ');

        // TestCheckConstraintMeetingState
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintMeetingState');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintMeetingState()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (7, 996, 996, 995, 997, 998, 994, NULL, NULL, "INVALID_STATE", NULL); 
                SELECT "Check constraint test for meeting_state failed" AS Result;
            END
        ');

        // TestCheckConstraintOneChampionshipOrTournament
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintOneChampionshipOrTournament');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintOneChampionshipOrTournament()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (6, 996, 996, 995, 997, 998, 994, NULL, NULL, "PLAYED", NULL);
                SELECT "Check constraint test for one_championship_or_tournament failed" AS Result;
            END
        ');

        // TestCheckConstraintRoles
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintRoles');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintRoles()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO user (id, email, first_name, last_name, roles, password, creation_date)
                VALUES (2, "invalid@example.com", "Invalid", "User", "[\"INVALID_ROLE\"]", "password", NOW()); 
                SELECT "Check constraint test for roles failed" AS Result;
            END
        ');

        // TestCheckConstraintTeamState
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintTeamState');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintTeamState()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO team (id, competition_id, owner_id, name, structure, creation_date, state)
                VALUES (3, 998, 999, "Check Constraint Test", "Structure", NOW(), "INVALID_STATE"); -- Should fail
                SELECT "Check constraint test for team_state failed" AS Result;
            END
        ');

        // TestCheckConstraintTeamsDifferent
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintTeamsDifferent');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintTeamsDifferent()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO meeting (id, blue_team_id, green_team_id, time_slot_id, tournament_id, champion_ship_id, stage_id, blue_score, green_score, state, comments)
                VALUES (5, 996, 996, 995, 997, 998, 994, NULL, NULL, "PLAYED", NULL); -- Should fail
                SELECT "Check constraint test for teams_different failed" AS Result;
            END
        ');

        // TestCheckConstraintTimeSlot
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintTimeSlot');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintTimeSlot()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO time_slot (id, name, start, end)
                VALUES (2, "Check Constraint Test", NOW() + INTERVAL 1 HOUR, NOW()); -- Should fail
                SELECT "Check constraint test for time_slot failed" AS Result;
            END
        ');

        // TestCheckConstraintTournament
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintTournament');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintTournament()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                 INSERT INTO tournament (id, name, start, end, lap)
                VALUES (2, "Check Constraint Test", CURDATE() + INTERVAL 1 DAY, CURDATE(), 1); -- Should fail
                SELECT "Check constraint test for tournament failed" AS Result;
            END
        ');

        // TestForeignKeyConstraintChampionShip
        $this->addSql('DROP PROCEDURE IF EXISTS TestForeignKeyConstraintChampionShip');
        $this->addSql('
            CREATE PROCEDURE TestForeignKeyConstraintChampionShip()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                 INSERT INTO champion_ship (id, organizer_id, name, start) VALUES (4, 9999, "Foreign Key Test", NOW()); -- Should fail
                SELECT "Foreign key constraint test for champion_ship failed" AS Result;
            END');

        // TestForeignKeyConstraintCompetition
        $this->addSql('DROP PROCEDURE IF EXISTS TestForeignKeyConstraintCompetition');
        $this->addSql('
            CREATE PROCEDURE TestForeignKeyConstraintCompetition()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                 INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
                VALUES (3, 998, 9999, 999, "Foreign Key Test", CURDATE(), CURDATE(), "Description"); -- Should fail
                SELECT "Foreign key constraint test for competition failed" AS Result;
            END');

        // TestTriggerChampionshipEndTournamentStart
        $this->addSql('DROP PROCEDURE IF EXISTS TestTriggerChampionshipEndTournamentStart');
        $this->addSql('
            CREATE PROCEDURE TestTriggerChampionshipEndTournamentStart()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
                VALUES (4, 998, 997, 999, "Trigger Test", CURDATE(), CURDATE(), "Description"); -- Should fail if dates do not match
                SELECT "Trigger test for TRG_CHAMPIONSHIP_END_TOURNAMENT_START failed" AS Result;
            END');

        // TestTriggerCheckOrganizerRole
        $this->addSql('DROP PROCEDURE IF EXISTS TestTriggerCheckOrganizerRole');
        $this->addSql('
            CREATE PROCEDURE TestTriggerCheckOrganizerRole()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                    INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
                VALUES (5, 998, 997, 9999, "Trigger Test", CURDATE(), CURDATE(), "Description"); -- Should fail if organizer does not have ROLE_ORGA
                SELECT "Trigger test for TRG_CHECK_ORGANIZER_ROLE failed" AS Result;
            END');

        // TestTriggerCheckOwnerRole
        $this->addSql('DROP PROCEDURE IF EXISTS TestTriggerCheckOwnerRole');
        $this->addSql('
            CREATE PROCEDURE TestTriggerCheckOwnerRole()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                    INSERT INTO team (id, competition_id, owner_id, name, structure, creation_date, state)
                    VALUES (2, 998, 9999, "Trigger Test", "Structure", NOW(), "ACTIVE"); -- Should fail if owner does not have ROLE_USER
                    SELECT "Trigger test for TRG_CHCK_OWNER_ROLE failed" AS Result;
                END');

        // TestTriggerCompetitionDates
        $this->addSql('DROP PROCEDURE IF EXISTS TestTriggerCompetitionDates');
        $this->addSql('
            CREATE PROCEDURE TestTriggerCompetitionDates()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO competition (id, champion_ship_id, tournament_id, organizer_id, name, start, end, description)
            VALUES (6, 998, 997, 999, "Trigger Test", CURDATE(), CURDATE(), "Description"); -- Should fail if dates overlap
            SELECT "Trigger test for TRG_COMPETITION_DATES failed" AS Result;
        END');

        // TestCheckConstraintChampionShip
        $this->addSql('DROP PROCEDURE IF EXISTS TestCheckConstraintChampionShip');
        $this->addSql('
            CREATE PROCEDURE TestCheckConstraintChampionShip()
            BEGIN
                DECLARE EXIT HANDLER FOR SQLEXCEPTION
                BEGIN
                    GET DIAGNOSTICS CONDITION 1 @sqlstate = RETURNED_SQLSTATE, @errno = MYSQL_ERRNO, @text = MESSAGE_TEXT;
                    SELECT CONCAT("Test passed: ", @errno, " ", @text) AS Result;
                END;

                INSERT INTO champion_ship (id, organizer_id, name, start, end) VALUES (5, 999, "Check Constraint Test", NOW() + INTERVAL 1 DAY, NOW()); -- Should fail
                SELECT "Check constraint test for champion_ship failed" AS Result;
            END');
        

    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition DROP CONSTRAINT CHK_COMPETITION_START_BEFORE_END');
        $this->addSql('ALTER TABLE tournament DROP CONSTRAINT CHK_TOURNAMENT_START_BEFORE_END');
        $this->addSql('ALTER TABLE champion_ship DROP CONSTRAINT CHK_CHAMPIONSHIP_START_BEFORE_END');
        $this->addSql('ALTER TABLE time_slot DROP CONSTRAINT CHK_TIMESLOT_ORDER');
        $this->addSql('ALTER TABLE meeting DROP CONSTRAINT CHK_BLUE_SCORE_POSITIVE');
        $this->addSql('ALTER TABLE meeting DROP CONSTRAINT CHK_GREEN_SCORE_POSITIVE');
        $this->addSql('ALTER TABLE meeting DROP CONSTRAINT CHK_TEAMS_DIFFERENT');
        $this->addSql('ALTER TABLE meeting DROP CONSTRAINT CHK_ONE_CHAMPIONSHIP_OR_TOURNAMENT');
        $this->addSql('ALTER TABLE user DROP CONSTRAINT CHK_ROLES');
        $this->addSql('ALTER TABLE meeting DROP CONSTRAINT CHK_MEETING_STATE');
        $this->addSql('ALTER TABLE team DROP CONSTRAINT CHK_TEAM_STATE');

        // Triggers

        $this->addSql('DROP TRIGGER IF EXISTS TRG_CHAMPIONSHIP_END_TOURNAMENT_START');
        $this->addSql('DROP TRIGGER IF EXISTS TRG_COMPETITION_DATES');
        $this->addSql('DROP TRIGGER IF EXISTS TRG_OVERLAP_MEETING');
        $this->addSql('DROP TRIGGER IF EXISTS TRG_CHCK_OWNER_ROLE');
        $this->addSql('DROP TRIGGER IF EXISTS TRG_CHCK_ORGANIZER_ROLE');

        // Views 

        $this->addSql('DROP VIEW team_scores');
    }
}
