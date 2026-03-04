jQuery(function($) {
    // Handle form submissions for printers and filaments
    $('form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var data = $form.serialize();
        
        $.post(ajaxurl, data, function(resp) {
            if (resp.success) {
                location.reload();
            } else {
                alert('Error: ' + (resp.data || 'Unknown'));
            }
        }).fail(function() {
            alert('Request failed');
        });
    });
});
