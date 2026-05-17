export async function loadScenarioBuilderState(stateUrl) {
    const response = await fetch(stateUrl, {
        headers: {
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });

    return parseJsonResponse(response);
}

export async function saveScenarioBuilderState(saveUrl, csrfToken, payload) {
    const response = await fetch(saveUrl, {
        method: 'PUT',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    return parseJsonResponse(response);
}

export async function publishScenarioBuilderState(publishUrl, csrfToken, payload) {
    const response = await fetch(publishUrl, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });

    return parseJsonResponse(response);
}

async function parseJsonResponse(response) {
    const data = await response.json().catch(() => ({}));

    if (! response.ok) {
        const error = new Error(data.message || 'Request failed');
        error.status = response.status;
        error.data = data;

        throw error;
    }

    return data;
}
