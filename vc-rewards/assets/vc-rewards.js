/* Reveal a code, then vote. One request each, through admin-ajax. */
(function () {
    'use strict';
    if (!window.vcRewards) {
        return;
    }

    function post(action, data) {
        var body = new URLSearchParams(data);
        body.append('action', action);
        body.append('nonce', vcRewards.nonce);
        return fetch(vcRewards.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function message(card, text) {
        var el = card.querySelector('.vc-rewards-result');
        if (el) {
            el.textContent = text;
        }
    }

    document.addEventListener('click', function (e) {
        var card = e.target.closest('.vc-rewards-card');
        if (!card) {
            return;
        }
        var id = card.getAttribute('data-contribution');

        if (e.target.classList.contains('vc-rewards-reveal-btn')) {
            e.target.disabled = true;
            post('vc_rewards_reveal', { contribution: id }).then(function (res) {
                if (!res.success) {
                    e.target.disabled = false;
                    message(card, (res.data && res.data.message) || vcRewards.error);
                    return;
                }
                var code = card.querySelector('.vc-rewards-code');
                code.textContent = res.data.code || '';
                code.hidden = !res.data.code;
                if (res.data.url) {
                    var a = document.createElement('a');
                    a.href = res.data.url;
                    a.target = '_blank';
                    a.rel = 'noopener nofollow sponsored';
                    a.textContent = 'Open store';
                    code.after(a);
                }
                e.target.hidden = true;
                card.querySelector('.vc-rewards-votes').hidden = false;
            }).catch(function () {
                e.target.disabled = false;
                message(card, vcRewards.error);
            });
            return;
        }

        if (e.target.classList.contains('vc-rewards-vote')) {
            var buttons = card.querySelectorAll('.vc-rewards-vote');
            buttons.forEach(function (b) { b.disabled = true; });
            post('vc_rewards_vote', { contribution: id, verdict: e.target.getAttribute('data-verdict') }).then(function (res) {
                message(card, (res.data && res.data.message) || vcRewards.error);
                if (!res.success) {
                    buttons.forEach(function (b) { b.disabled = false; });
                }
            }).catch(function () {
                buttons.forEach(function (b) { b.disabled = false; });
                message(card, vcRewards.error);
            });
        }
    });
})();
