document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('violations');
  if (!list) return;
  fetch('../api/violations.php')
    .then(res => res.json())
    .then(data => {
      if (!Array.isArray(data)) {
        list.textContent = 'No data';
        return;
      }
      list.innerHTML = data.map(v =>
        `<li>${v.violation_date} — ${v.motorist_name || 'Unknown'} — ${v.violation_name || 'Unknown'} — ${v.location} — ${v.status} — ₱${v.fine_amount}</li>`
      ).join('');
    })
    .catch(err => {
      console.error(err);
      list.textContent = 'Error loading violations';
    });
});