(function ($, Drupal, drupalSettings) {

    'use strict';
    var fioErrorWrapper = $('.fio-error');
    $('.js-form-submit').click(function (e) {

        var fioVal = $('#edit-fio').val();
        if(fioVal.length){
            var words = fioVal.split(" ");
            if(words.length !== 3) {
                fioErrorWrapper.css({'color': 'red'}).text('Неверно заполнено поле.').show();
            } else {
                return true;
            }
        } else {
            fioErrorWrapper.css({'color': 'red'}).text('Необходимо заполнить поле.').show();
        }
        return false;
    })
})(jQuery, Drupal, drupalSettings);