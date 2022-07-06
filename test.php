<h5>Mark's Test Page</h5>

<pre style='white-space: pre-wrap; max-width: 90%'>
Loading (may take a minute)...
</pre>

<script>
    fetch(<?=json_encode($module->getUrl('ajax.php'))?>)
    .then(response => response.text())
    .then(data => {
        document.querySelector('#center pre').innerHTML = data
    })
</script>