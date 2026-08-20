<div class="save-bar">
    <p style="font-size:12px;color:#9ca3af">Changes take effect immediately after saving.</p>
    <button type="submit" class="save-btn">Save Changes</button>
</div>

<script>
function toggleSection(id, header) {
    const body   = document.getElementById(id);
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    header.classList.toggle('open', !isOpen);
    const icon = header.querySelector('.icon');
    if (icon) icon.textContent = isOpen ? '+' : '−';
}
</script>
