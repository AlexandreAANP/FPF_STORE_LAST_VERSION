var querybizFavorites = {
    CONST_elMsg: null,
    init: function (options) {
        querybizFavorites.CONST_elMsg = $('#customer-my-account-msg');
        var successMessage = options.hasOwnProperty('successMessage') ? options.successMessage : '';
        var errorMessage = options.hasOwnProperty('errorMessage') ? options.errorMessage : '';

        $('.btn-add-favorites').click(function() {

            var selfButton = $(this);
            selfButton.attr('disabled', 'disabled');

            let url = '';
            let action = 0;
            let productId = $(this).data('product-id');

            if ($(this).children().hasClass('fa')) {
                url = '/customer/favorites/delete?id=' + productId;
            } else {
                action = 1;
                url = '/customer/favorites/save?id=' + productId;
            }
            $('.spinner-border').remove();
            let loader ='<sup style="width:1rem;height:1rem" class="ml-1 spinner-border text-info spinner-border text-danger"></sup>';

            selfButton.append(loader);

            querybiz.post(url, function(data) {
                selfButton.removeAttr('disabled');
                selfButton.find('.spinner-border').remove();
                let favoritesId = $('.favorites-' + productId).children();
                if (action) {
                    favoritesId.removeClass('far').addClass('fa');
                    selfButton.prop('title',  selfButton.data('title-on'));
                } else {
                    favoritesId.removeClass('fa').addClass('far');
                    selfButton.prop('title',  selfButton.data('title-off'));
                    }
                },
                function(data){
                    selfButton.removeAttr('disabled');
                    selfButton.find('.spinner-border').remove();
                    alert(errorMessage);
                });
        });

        $('.btn-delete-favorites').click(function(){
            $('#modal_delete').modal('show');
            let productId = $(this).data('product-id');
            $('.delete-favorites input[name=id]').val(productId);
        });

        $('#modal_delete .btn-danger').click(function(){
            $('#modal_delete').modal('hide');
            let elMsg = querybizFavorites.CONST_elMsg;
            let form = $('.delete-favorites');
            let favoritesId = $('input[name=id]').val();

            let html = $(this).html();
            let loader ='<sup class="ml-1 spinner-border spinner-border-sm"></sup>';
            $(this).html(html + loader);

            querybiz.post($(form), function(data) {
                    let id = form.find('id').val();

                    $('#modal_delete').modal('hide');
                    $('.favorites-' + favoritesId).remove();
                    elMsg.text(successMessage).removeClass('d-none').addClass('alert-success');
                    elMsg.fadeIn().delay(2000).fadeOut(function () {
                        elMsg.removeClass('alert-success');
                    });

                    $('#modal_delete').find('.btn-danger sup').remove();
                    $('.favorites-' + id).fadeOut();
                },
                function(data) {
                    $('#modal_delete').modal('hide');
                    $('.spinner-border').remove();
                    elMsg.text(errorMessage).removeClass('d-none').addClass('alert-danger');
                    elMsg.fadeIn().delay(5000).fadeOut(function () {
                        elMsg.removeClass('alert-danger');
                    });
                });
        });
    }
};