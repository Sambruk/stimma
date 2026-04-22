/**
 * Återanvändbar "två spärrar"-bekräftelse för destruktiva operationer.
 *
 * Använd så här (i en Bootstrap-modal):
 *   - Ha en kryssruta med class="confirm-understand"
 *   - Ha ett textfält med class="confirm-type-radera" (placeholder "Skriv RADERA")
 *   - Ha en knapp med class="confirm-destructive-btn" som ska aktiveras
 *
 * Ring initConfirmDestructive(modalElement) när modalen renderas.
 * Knappen aktiveras bara om kryssrutan är ikryssad OCH textfältet exakt = "RADERA".
 */
function initConfirmDestructive(modal) {
    const check = modal.querySelector('.confirm-understand');
    const input = modal.querySelector('.confirm-type-radera');
    const btn = modal.querySelector('.confirm-destructive-btn');
    if (!check || !input || !btn) return;

    function update() {
        btn.disabled = !(check.checked && input.value.trim() === 'RADERA');
    }

    check.addEventListener('change', update);
    input.addEventListener('input', update);

    // Reset-tillstånd när modalen öppnas/stängs
    modal.addEventListener('shown.bs.modal', () => {
        check.checked = false;
        input.value = '';
        update();
    });
    modal.addEventListener('hidden.bs.modal', () => {
        check.checked = false;
        input.value = '';
        update();
    });

    update();
}
