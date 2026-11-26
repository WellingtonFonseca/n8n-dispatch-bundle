(function (Mautic, mQuery) {
    'use strict';

    // data-onload-callback of the "Enviar por" select (Form/Extension/EmailProviderExtension.php): core's Email
    // template has no extension point above the Subject, so the row is moved there once the page is loaded.
    Mautic.n8nDispatchMoveEmailProviderField = function (el) {
        var $row = mQuery(el).closest('.form-group');
        var $subject = mQuery('#emailform_subject').closest('.form-group');

        if ($row.length && $subject.length && !$row.next().is($subject)) {
            $row.insertBefore($subject);
        }
    };
})(window.Mautic, window.mQuery);
