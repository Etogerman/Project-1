import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';
import './style.css';

document.querySelectorAll('[data-scenario-builder-v3]').forEach((element) => {
    if (element.dataset.reactMounted === 'true') {
        return;
    }

    element.dataset.reactMounted = 'true';

    createRoot(element).render(
        <App
            stateUrl={element.dataset.stateUrl}
            saveUrl={element.dataset.saveUrl}
            publishUrl={element.dataset.publishUrl}
            csrfToken={element.dataset.csrfToken}
        />,
    );
});
