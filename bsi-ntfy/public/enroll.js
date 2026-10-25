// Zeichnet den TOTP-QR-Code lokal im Browser (das Geheimnis verlässt die Seite nicht).
(function () {
  var el = document.getElementById('qr');
  if (el && window.QRCode) {
    new QRCode(el, { text: el.dataset.uri, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
  }
})();
