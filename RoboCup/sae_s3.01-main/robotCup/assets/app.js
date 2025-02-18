import './bootstrap.js';
// assets/app.js
import './styles/app.css';

// Importation de React
import React from 'react';
import ReactDOM from 'react-dom';
import MatchStats from './react/controllers/MatchStats.js';

// Function to init React
const initReact = () => {
    const matchStatsContainer = document.getElementById('match-stats');
    if (matchStatsContainer) {
        try {
            const props = {
                teamStats: JSON.parse(matchStatsContainer.dataset.teamStats || '[]'),
                meetings: JSON.parse(matchStatsContainer.dataset.meetings || '[]')
            };
            ReactDOM.render(React.createElement(MatchStats, props), matchStatsContainer);
        } catch (error) {
            console.error('Error rendering React component:', error);
        }
    }
};

// Initialize when DOM is ready
if (document.readyState !== 'loading') {
    initReact();
} else {
    document.addEventListener('DOMContentLoaded', initReact);
}