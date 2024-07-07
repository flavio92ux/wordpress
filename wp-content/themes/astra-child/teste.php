<?php
/*
 * Template Name: Teste
 */
?>

<h1>Teste!!!</h1>

<button id="clicar">Click</button>

<?php $custom_js_url = get_stylesheet_directory_uri() . '/assets/js/custom.js'; ?>

<?php $hidden_variable = 'This will not appear in the rendered HTML'; ?>

<script src="<?php echo $custom_js_url ?>"></script>