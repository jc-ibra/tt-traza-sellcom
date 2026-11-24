// Selección masiva en el listado de equipos
document.addEventListener('DOMContentLoaded', () => {
  const todos = document.getElementById('sel-todos');
  const checks = () => Array.from(document.querySelectorAll('.sel-eq'));
  const contador = document.getElementById('sel-contador');
  const barra = document.getElementById('barra-masiva');
  const actualizar = () => {
    const n = checks().filter(c => c.checked).length;
    if (contador) contador.textContent = n;
    if (barra) barra.classList.toggle('d-none', n === 0);
  };
  if (todos) todos.addEventListener('change', () => { checks().forEach(c => c.checked = todos.checked); actualizar(); });
  checks().forEach(c => c.addEventListener('change', actualizar));
  actualizar();

  // Confirmaciones
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', ev => { if (!confirm(el.dataset.confirm)) ev.preventDefault(); });
  });

  // Auto-enviar filtros al cambiar selects
  document.querySelectorAll('form.auto-submit select').forEach(s => s.addEventListener('change', () => s.form.submit()));
});
