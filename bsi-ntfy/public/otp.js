// Code-Felder: beim Tippen oder Einfügen alles außer Ziffern entfernen.
document.querySelectorAll('input[data-otp]').forEach(function (el) {
  el.addEventListener('input', function () {
    var digits = el.value.replace(/\D+/g, '');
    if (digits !== el.value) el.value = digits;
  });
});
