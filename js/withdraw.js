// Lightweight JS for withdraw page to avoid CSP/HTML event handler issues
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') return fn();
    document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var selectedResource = null;

    // Delegate clicks from the resources panel
    var panel = document.querySelector('.available-resources');
    if (panel) {
      panel.addEventListener('click', function (e) {
        var el = e.target.closest('.resource-item');
        if (!el) return;
        selectResource(el);
      });
    }

    var qtyEl = document.getElementById('quantity');
    if (qtyEl) qtyEl.addEventListener('input', updateQuantityPreview);

    function selectResource(element) {
      document.querySelectorAll('.resource-item').forEach(function (item) {
        item.classList.remove('selected');
      });
      element.classList.add('selected');

      var resourceId = element.getAttribute('data-resource-id');
      var resourceName = element.getAttribute('data-resource-name');
      var available = parseFloat(element.getAttribute('data-available')) || 0;

      selectedResource = { id: resourceId, name: resourceName, available: available };

      var idEl = document.getElementById('resource_id');
      if (idEl) idEl.value = resourceId;
      var sel = document.getElementById('selected-resource');
      if (sel) sel.innerHTML = '<strong>' + escapeHtml(resourceName) + '</strong><br><small>Available: ' + available.toFixed(2) + '</small>';

      enableForm(available);
      document.getElementById('available-amount').textContent = available.toFixed(2);
      updateQuantityPreview();
    }

    function enableForm(available) {
      var q = document.getElementById('quantity');
      var p = document.getElementById('purpose');
      var n = document.getElementById('notes');
      var s = document.getElementById('submit-btn');
      if (q) { q.disabled = false; q.max = available; }
      if (p) p.disabled = false;
      if (n) n.disabled = false;
      if (s) s.disabled = false;
    }

    function updateQuantityPreview() {
      if (!selectedResource) return;
      var q = parseFloat(document.getElementById('quantity').value) || 0;
      var remaining = selectedResource.available - q;
      document.getElementById('remaining-amount').textContent = remaining.toFixed(2);
      document.getElementById('quantity-preview').style.display = q > 0 ? 'flex' : 'none';
      if (q > selectedResource.available) {
        document.getElementById('quantity').setCustomValidity('Quantity exceeds available amount');
      } else {
        document.getElementById('quantity').setCustomValidity('');
      }
    }

    function escapeHtml(s) {
      return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }
  });
})();


