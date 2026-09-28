function formatFolio(value) {
    const digits = String(value).replace(/\D/g, '').slice(0, 10);
    const numberPart = digits.slice(0, 6);
    const yearPart = digits.slice(6, 10);

    return `CC-${numberPart}${yearPart ? `-${yearPart}` : ''}`;
}

function formatDate(value) {
    const digits = String(value).replace(/\D/g, '').slice(0, 8);
    const day = digits.slice(0, 2);
    const month = digits.slice(2, 4);
    const year = digits.slice(4, 8);

    return [day, month, year].filter(Boolean).join('/');
}

window.formatFolio = formatFolio;
window.formatDate = formatDate;

document.addEventListener('DOMContentLoaded', () => {
    const folio = document.querySelector('#folio');
    const issuedDate = document.querySelector('#fecha_emision');
    const modal = document.querySelector('.modal-backdrop');
    const closeModal = document.querySelector('[data-close-modal]');
    const form = document.querySelector('.validation-form');
    const submit = form ? form.querySelector('button[type="submit"]') : null;

    if (folio) {
        folio.addEventListener('focus', () => {
            if (! folio.value) {
                folio.value = 'CC-';
            }
        });

        folio.addEventListener('input', () => {
            folio.value = formatFolio(folio.value);
        });
    }

    if (issuedDate) {
        issuedDate.addEventListener('input', () => {
            issuedDate.value = formatDate(issuedDate.value);
        });
    }

    if (modal && closeModal) {
        closeModal.addEventListener('click', () => {
            modal.classList.remove('is-open');
            modal.setAttribute('hidden', 'hidden');
        });
    }

    if (form && submit) {
        form.addEventListener('submit', () => {
            submit.disabled = true;
        });
    }
});
