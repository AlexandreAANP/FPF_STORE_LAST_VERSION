const querybizAsyncProducts = function () {

    const asyncProducts = function () {

        const categoryWidgetFilterId = '#category_widget_filter';
        const checkbox = categoryWidgetFilterId + ' input[type=checkbox]';
        const url = $(categoryWidgetFilterId).data('action');
        const paginationAjaxId = '#pagination_ajax';
        const pageLink = paginationAjaxId + ' .page-link';
        const pageItem = '.page-item';

        $(document).on('change', checkbox, function () {
            const currentCheckbox = $(this);

            $(document).find(checkbox).prop('checked', false);
            currentCheckbox.prop('checked', true);

            showLoading(currentCheckbox);

            $.get(url, {category: currentCheckbox.val()}, function (response) {
                const paginationAjaxWrapper = $('#pagination_ajax_wrapper');

                hideLoading(currentCheckbox);

                if (response.htmlSubcategories) {
                    currentCheckbox.closest('.closest').next().html(response.htmlSubcategories);
                }

                if (response.htmlPagination) {
                    $('#pagination_default').remove();
                    paginationAjaxWrapper.html(response.htmlPagination);
                } else {
                    paginationAjaxWrapper.html(null);
                }

                responseProducts(response);
            }, 'json');
        });

        $(document).on('click', pageLink, function (event) {
            event.preventDefault();

            const currentPageLink = $(this);
            const page = currentPageLink.data('page');
            const category = $(document).find(checkbox).filter(':checked').val();

            $(pageLink).closest(pageItem).removeClass('active');
            $(this).closest(pageItem).addClass('active');

            showLoading();

            $.get(url, {page: page, category: category}, function (response) {
                hideLoading();
                responseProducts(response);
            }, 'json');
        });

        $(document).on('click', paginationAjaxId + ' .arrow', function (event) {
            event.preventDefault();

            const page = $(this).data('page');

            $(pageLink).closest(pageItem).removeClass('active');
            if (page === 1) {
                $(this).closest(pageItem).next().addClass('active');
            } else {
                $(this).closest(pageItem).prev().addClass('active');
            }
        });

        function showLoading(checkbox = null) {
            const spinner = '<div class="spinner-border spinner-border-sm text-info ml-2" role="status"><span class="sr-only">' + querybiz.trans('Loading') + '...</span></div>';

            if (checkbox) {
                checkbox.closest('.closest').find('label').append(spinner);
            }
            querybiz.showLoadingPage();
        }

        function hideLoading(checkbox = null) {
            if (checkbox) {
                checkbox.closest('.closest').find('.spinner-border').remove();
            }
            querybiz.hideLoadingPage();
        }

        function responseProducts(response) {
            const productListWrapper = $('#product_list_wrapper');

            if (response.htmlProducts) {
                productListWrapper.html(response.htmlProducts);
            } else {
                productListWrapper.html(querybiz.bootstrapAlert('info', 'No products yet!'));
            }
        }

    };

    return {
        init: function () {
            asyncProducts();
        }
    };

}();

jQuery(document).ready(function () {
    querybizAsyncProducts.init();
});
