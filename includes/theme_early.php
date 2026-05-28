<script>
(function () {
    try {
        if (localStorage.getItem('mtmo-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (e) {}
})();
</script>
