/*
 |----------------------------------------------------------------------
 | Image & video upload
 |----------------------------------------------------------------------
 | Client-side previews and a remaining-slots counter. The cap and the file
 | validation are enforced server-side; this only stops the owner picking
 | eight files and finding out after the upload that three were dropped.
 |
 | The same dropzone markup serves both kinds -- data-upload-kind="video"
 | switches the preview from <img> to a muted, non-autoplaying <video> so a
 | walkthrough clip gets a thumbnail-scrubber instead of a broken image icon.
 */

function formatSize(bytes) {
    return bytes > 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.round(bytes / 1024)} KB`;
}

function render(root) {
    const input = root.querySelector('[data-upload-input]');
    const previews = root.querySelector('[data-upload-previews]');
    const counter = root.querySelector('[data-upload-counter]');
    const remaining = parseInt(root.dataset.uploadRemaining, 10);
    const maxKb = parseInt(root.dataset.uploadMaxKb, 10);
    const isVideo = root.dataset.uploadKind === 'video';

    previews.innerHTML = '';

    const files = [...(input.files || [])];
    let oversize = 0;

    files.slice(0, remaining).forEach((file) => {
        if (file.size / 1024 > maxKb) oversize += 1;

        const item = document.createElement('div');
        item.className = 'upload-preview';

        const media = document.createElement(isVideo ? 'video' : 'img');
        media.src = URL.createObjectURL(file);
        // Revoke once the browser has decoded it, or every re-render leaks a blob.
        const revoke = () => URL.revokeObjectURL(media.src);

        if (isVideo) {
            media.muted = true;
            media.playsInline = true;
            media.preload = 'metadata';
            media.onloadeddata = revoke;
        } else {
            media.alt = '';
            media.onload = revoke;
        }

        const meta = document.createElement('span');
        meta.className = 'upload-preview-meta';
        meta.textContent = formatSize(file.size);

        item.append(media, meta);

        if (file.size / 1024 > maxKb) {
            item.classList.add('is-invalid');
            meta.textContent += ' — too large';
        }

        previews.appendChild(item);
    });

    const messages = [];

    if (files.length > remaining) {
        messages.push(
            `Only ${remaining} more can be added — ${files.length - remaining} will be ignored.`
        );
    }

    if (oversize > 0) {
        messages.push(`${oversize} file${oversize === 1 ? '' : 's'} exceed the size limit.`);
    }

    if (counter) {
        counter.textContent = messages.length
            ? messages.join(' ')
            : `${files.length} selected · ${remaining} slot${remaining === 1 ? '' : 's'} available`;
        counter.classList.toggle('is-warning', messages.length > 0);
    }
}

export function initImageUpload() {
    document.querySelectorAll('[data-upload]').forEach((root) => {
        const input = root.querySelector('[data-upload-input]');
        if (!input) return;

        input.addEventListener('change', () => render(root));

        const trigger = root.querySelector('[data-upload-trigger]');
        if (trigger) {
            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                input.click();
            });
        }
    });
}
