/* Adds the page's CSRF token to every same-origin request that changes something. */
(function () {
    'use strict';
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.content : '';
    if (!token || !window.fetch) return;
    var original = window.fetch.bind(window);
    window.fetch = function (input, init) {
        init = init || {};
        var method = String(init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        var url = new URL(input instanceof Request ? input.url : String(input), location.href);
        if (method !== 'GET' && method !== 'HEAD' && url.origin === location.origin) {
            var headers = new Headers(init.headers || (input instanceof Request ? input.headers : undefined));
            headers.set('X-CSRF-Token', token);
            init.headers = headers;
        }
        return original(input, init);
    };
    window.latexCsrfToken = token;
})();
