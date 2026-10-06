(function ($) {
    'use strict';

    if (!$) {
        const error = document.getElementById('login-error');
        error.textContent = 'The sign-in form could not load. Check your connection and refresh this page.';
        error.classList.remove('d-none');
        return;
    }

    const $form = $('#login-form');
    const $submit = $('#login-submit').prop('disabled', false);
    const $error = $('#login-error');
    let pending = false;

    $form.on('submit', function (event) {
        event.preventDefault();
        if (pending || !$form[0].reportValidity()) {
            return;
        }
        pending = true;
        $error.addClass('d-none').text('');
        $form.find('.is-invalid').removeClass('is-invalid').removeAttr('aria-invalid');
        $form.find('.invalid-feedback').text('');
        $form.attr('aria-busy', 'true');
        $submit.prop('disabled', true).text('Signing in…');
        $('#login-status').text('Signing in.');

        $.ajax({
            url: 'api/auth/login.php',
            method: 'POST',
            contentType: 'application/json',
            dataType: 'json',
            timeout: 15000,
            headers: { 'X-CSRF-Token': $('meta[name="csrf-token"]').attr('content') },
            data: JSON.stringify({ username: $('#username').val().trim(), password: $('#password').val() })
        }).done(function (response) {
            if (response.ok === true) {
                $('#password').val('');
                if (response.data && typeof response.data.csrf_token === 'string') {
                    $('meta[name="csrf-token"]').attr('content', response.data.csrf_token);
                }
                window.location.assign('index.php');
                return;
            }
            showError(response);
        }).fail(function (xhr) {
            showError(xhr.responseJSON);
        }).always(function () {
            pending = false;
            $form.removeAttr('aria-busy');
            $submit.prop('disabled', false).text('Sign in');
            $('#login-status').text('');
        });
    });

    function showError(response) {
        const message = response && typeof response.message === 'string'
            ? response.message
            : 'Unable to sign in. Check your connection and try again.';
        const errors = response && response.errors ? response.errors : {};
        ['username', 'password'].forEach(function (field) {
            if (typeof errors[field] === 'string') {
                $('#' + field).addClass('is-invalid').attr('aria-invalid', 'true');
                $('#' + field + '-error').text(errors[field]);
            }
        });
        $error.text(message).removeClass('d-none').trigger('focus');
    }
})(window.jQuery);
