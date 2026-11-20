'use strict';

const form = document.querySelector('#transcription-form');
const fileInput = document.querySelector('#media-file');
const youtubeInput = document.querySelector('#youtube-url');
const youtubeTab = document.querySelector('#youtube-tab');
const tabs = document.querySelectorAll('.source-tab');
const dropZone = document.querySelector('#drop-zone');
const fileSelection = document.querySelector('#file-selection');
const submitButton = document.querySelector('#transcribe');
const statusBox = document.querySelector('#job-state');
const statusMessage = document.querySelector('#status-message');
const uploadProgress = document.querySelector('#upload-progress');
const result = document.querySelector('#result');
const transcript = document.querySelector('#transcription');
const articleButton = document.querySelector('#generate-article');
const articleStatusBox = document.querySelector('#article-state');
const articleStatusMessage = document.querySelector('#article-status');
const articleDraft = document.querySelector('#article-draft');
const articleTitle = document.querySelector('#article-title');
const articleSubtitle = document.querySelector('#article-subtitle');
const articleParagraphs = document.querySelector('#article-paragraphs');
const maxUploadBytes = Number(form.dataset.maxBytes);
const storageKey = 'transar:job';

let selectedFile = null;
let currentJob = null;

function showStatus(kind, message) {
    statusBox.dataset.kind = kind;
    statusBox.hidden = false;
    if (statusMessage.textContent !== message) {
        statusMessage.textContent = message;
    }
}

function setBusy(busy) {
    submitButton.disabled = busy;
    submitButton.textContent = busy ? 'Transcripción en curso' : 'Transcribir';
    fileInput.disabled = busy;
    youtubeInput.disabled = busy;
    tabs.forEach(tab => { tab.disabled = busy; });
}

function selectFile(file) {
    selectedFile = file;
    fileSelection.textContent = file ? file.name : 'o arrastralo hasta acá';
    if (file && file.size > maxUploadBytes) {
        showStatus('error', `El archivo supera el límite de ${Math.floor(maxUploadBytes / 1048576)} MiB.`);
    } else if (statusBox.dataset.kind === 'error') {
        statusBox.hidden = true;
        statusMessage.textContent = '';
    }
}

function storeJob(job) {
    try {
        if (job) {
            sessionStorage.setItem(storageKey, JSON.stringify(job));
        } else {
            sessionStorage.removeItem(storageKey);
        }
    } catch {
        // Transcription still works when session storage is unavailable.
    }
}

function readStoredJob() {
    try {
        const job = JSON.parse(sessionStorage.getItem(storageKey));
        return typeof job?.id === 'string' && typeof job.token === 'string' ? job : null;
    } catch {
        return null;
    }
}

function clearTranscript() {
    result.hidden = true;
    transcript.replaceChildren();
    currentJob = null;
    showArticle();
    articleTitle.textContent = '';
    articleSubtitle.textContent = '';
    articleParagraphs.replaceChildren();
}

function formatTranscript(text) {
    const boundary = /([.!?…]+[”’"»')\]]*)[\p{Zs}\t]+(?=\S)/gu;
    const title = /\b(?:sr(?:a|ta|es|as|tas)?|dr(?:a|es|as)?|prof(?:a)?|ing|lic|arq|mr|mrs|ms|núm|nro|págs?)$/iu;
    const initial = /(?:^|[^\p{L}\p{N}])\p{Lu}$/u;
    const abbreviation = /\b(?:[ap]\.[\p{Zs}\t]+m|p\.[\p{Zs}\t]+ej|ee\.[\p{Zs}\t]+uu|e\.g|i\.e|etc)$/iu;

    return text.replace(boundary, (separator, punctuation, offset) => {
        if (punctuation === '.') {
            // Match only punctuation followed by whitespace, so decimals and URL dots stay intact.
            const before = text.slice(Math.max(0, offset - 24), offset);
            const after = text.slice(offset + separator.length, offset + separator.length + 3);
            const insideAbbreviation = title.test(before)
                || (initial.test(before) && /^\p{Lu}/u.test(after))
                || (abbreviation.test(before) && /^\p{Ll}/u.test(after))
                || (/\b[ap]$/iu.test(before) && /^m\./iu.test(after))
                || (/\bp$/iu.test(before) && /^ej\./iu.test(after))
                || (/\bee$/iu.test(before) && /^uu\./iu.test(after));
            if (insideAbbreviation) {
                return separator;
            }
        }
        return `${punctuation}\n`;
    });
}

function showTranscript(text) {
    const paragraphs = document.createDocumentFragment();
    for (const line of formatTranscript(text).split(/\r?\n/)) {
        const sentence = line.trim();
        if (sentence) {
            const paragraph = document.createElement('p');
            paragraph.dir = 'auto';
            paragraph.textContent = sentence;
            paragraphs.append(paragraph);
        }
    }
    transcript.replaceChildren(paragraphs);
    result.hidden = false;
    showStatus('success', 'Transcripción lista.');
}

function showArticle(article) {
    const status = article?.status;
    const pending = status === 'queued' || status === 'processing';
    const ready = status === 'completed';
    articleButton.disabled = pending;
    articleButton.hidden = ready;
    articleButton.textContent = status === 'failed' ? 'Volver a intentar' : 'Generar nota periodística';
    articleDraft.hidden = !ready;
    articleStatusBox.hidden = !status;

    if (ready) {
        articleTitle.textContent = article.title;
        articleSubtitle.textContent = article.subtitle;
        articleParagraphs.replaceChildren(...article.paragraphs.map(text => {
            const paragraph = document.createElement('p');
            paragraph.textContent = text;
            return paragraph;
        }));
    }

    const messages = {
        queued: 'La nota está en espera…',
        processing: 'Redactando el borrador…',
        completed: 'Borrador listo.',
        failed: 'No se pudo generar la nota.',
    };
    articleStatusBox.dataset.kind = ready ? 'success' : pending ? 'progress' : 'error';
    const message = article?.message || messages[status] || '';
    if (articleStatusMessage.textContent !== message) {
        articleStatusMessage.textContent = message;
    }
    return pending;
}

function requestJob(job, action, method = 'GET') {
    return fetch(`api.php?action=${action}&id=${encodeURIComponent(job.id)}`, {
        method,
        headers: { 'X-Job-Token': job.token },
        cache: 'no-store',
        signal: AbortSignal.timeout(15000),
    });
}

async function requestArticle(job, method) {
    const response = await requestJob(job, 'article', method).catch(() => null);
    if (!response) {
        throw new Error('No se pudo conectar con Transar. Volvé a intentar.');
    }
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.article) {
        throw new Error(data.error || 'No se pudo consultar la nota. Volvé a intentar.');
    }
    return data.article;
}

async function watchArticle(job) {
    let failures = 0;
    while (currentJob === job) {
        await new Promise(resolve => setTimeout(resolve, 2500 + failures * 1000));
        if (currentJob !== job) {
            return;
        }
        try {
            const article = await requestArticle(job, 'GET');
            if (currentJob !== job || !showArticle(article)) {
                return;
            }
            failures = 0;
        } catch {
            if (currentJob !== job) {
                return;
            }
            failures += 1;
            if (failures >= 6) {
                showArticle({status: 'failed', message: 'No se pudo consultar la nota. Volvé a intentar.'});
                return;
            }
            showArticle({status: 'processing', message: 'Reconectando para consultar la nota…'});
        }
    }
}

articleButton.addEventListener('click', async () => {
    const job = currentJob;
    if (!job || articleButton.disabled) {
        return;
    }
    showArticle({status: 'queued'});
    try {
        const article = await requestArticle(job, 'POST');
        if (currentJob === job && showArticle(article)) {
            await watchArticle(job);
        }
    } catch (error) {
        if (currentJob === job) {
            showArticle({status: 'failed', message: error.message});
        }
    }
});

function createJob(body, isUpload) {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.open('POST', 'api.php?action=create');
        request.responseType = 'json';

        if (isUpload) {
            uploadProgress.removeAttribute('value');
            uploadProgress.hidden = false;
            request.upload.addEventListener('progress', event => {
                if (event.lengthComputable) {
                    uploadProgress.value = event.loaded * 100 / event.total;
                }
            });
            request.upload.addEventListener('load', () => {
                uploadProgress.hidden = true;
                showStatus('progress', 'Preparando el archivo…');
            });
        }

        request.addEventListener('load', () => {
            const data = request.response;
            if (request.status === 202 && typeof data?.id === 'string' && typeof data.token === 'string') {
                resolve({ id: data.id, token: data.token });
                return;
            }
            const fallback = request.status === 413
                ? 'El archivo supera el tamaño máximo permitido por el servidor.'
                : 'No se pudo iniciar la transcripción. Probá de nuevo.';
            reject(new Error(data?.error || fallback));
        });
        request.addEventListener('error', () => reject(new Error('Se interrumpió la conexión durante el envío. Probá de nuevo.')));
        request.send(body);
    });
}

async function watchJob(job) {
    const statuses = {
        queued: 'En espera para transcribir…',
        processing: 'Procesando el audio. Esto puede demorar unos minutos…',
    };
    let failures = 0;

    while (true) {
        try {
            const response = await requestJob(job, 'status');
            if (response.status >= 500 || response.status === 429) {
                throw new Error('Temporary status request failure');
            }

            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.status === 'failed') {
                storeJob(null);
                showStatus('error', data.error || data.message || 'No se pudo completar la transcripción.');
                return;
            }
            if (data.status === 'completed' && typeof data.text === 'string') {
                currentJob = job;
                if (showArticle(data.article)) {
                    void watchArticle(job);
                }
                showTranscript(data.text);
                return;
            }
            if (!Object.hasOwn(statuses, data.status)) {
                throw new Error('Unexpected transcription status');
            }

            failures = 0;
            showStatus('progress', data.message || statuses[data.status]);
        } catch {
            failures += 1;
            if (failures >= 6) {
                showStatus('error', 'No se pudo consultar el estado. Recargá la página para volver a consultar el trabajo.');
                return;
            }
            showStatus('progress', 'Reconectando para consultar la transcripción…');
        }

        await new Promise(resolve => setTimeout(resolve, 2000 + failures * 1000));
    }
}

fileInput.addEventListener('change', () => selectFile(fileInput.files[0] || null));

dropZone.addEventListener('dragover', event => {
    if (!event.dataTransfer.types.includes('Files')) {
        return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = fileInput.disabled ? 'none' : 'copy';
    dropZone.classList.toggle('is-dragging', !fileInput.disabled);
});

dropZone.addEventListener('dragleave', event => {
    if (!dropZone.contains(event.relatedTarget)) {
        dropZone.classList.remove('is-dragging');
    }
});

dropZone.addEventListener('drop', event => {
    event.preventDefault();
    dropZone.classList.remove('is-dragging');
    if (fileInput.disabled || event.dataTransfer.files.length === 0) {
        return;
    }
    if (event.dataTransfer.files.length > 1) {
        showStatus('error', 'Elegí un archivo por vez.');
        return;
    }
    fileInput.files = event.dataTransfer.files;
    selectFile(fileInput.files[0]);
});

form.addEventListener('submit', async event => {
    event.preventDefault();
    if (submitButton.disabled) {
        return;
    }

    const body = new FormData();
    const isUpload = !youtubeTab.classList.contains('active');
    if (isUpload) {
        if (!selectedFile || selectedFile.size === 0) {
            fileInput.focus();
            showStatus('error', 'Elegí un archivo de audio o video.');
            return;
        }
        if (selectedFile.size > maxUploadBytes) {
            fileInput.focus();
            selectFile(selectedFile);
            return;
        }
        body.append('file', selectedFile);
    } else {
        youtubeInput.value = youtubeInput.value.trim();
        if (!youtubeInput.value || !youtubeInput.checkValidity()) {
            youtubeInput.focus();
            showStatus('error', 'Ingresá un enlace válido de YouTube.');
            return;
        }
        body.append('url', youtubeInput.value);
    }

    setBusy(true);
    showStatus('progress', isUpload ? 'Subiendo el archivo…' : 'Enviando el enlace…');
    try {
        const job = await createJob(body, isUpload);
        clearTranscript();
        storeJob(job);
        showStatus('progress', 'En espera para transcribir…');
        await watchJob(job);
    } catch (error) {
        showStatus('error', error.message);
    } finally {
        uploadProgress.hidden = true;
        setBusy(false);
    }
});

const previousJob = readStoredJob();
if (previousJob) {
    setBusy(true);
    showStatus('progress', 'Consultando la transcripción…');
    watchJob(previousJob).finally(() => setBusy(false));
} else {
    setBusy(false);
}
