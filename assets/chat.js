jQuery(function ($) {
    const $b = $('#owc-bubble');
    const $c = $('#owc-chat');
    const $m = $('#owc-messages');
    const $i = $('#owc-text');
    const $s = $('#owc-send');
    const $x = $('#owc-close');
    let first = true;
    let typingInterval = null;

    $c.addClass('closed');

    $b.on('click', () => {
        $c.toggleClass('closed');
        if (first) { setTimeout(welcome, 400); first = false; }
        setTimeout(() => $i.focus(), 500);
    });

    $x.on('click', () => {
        if ($m.children().length > 1 && confirm('Chatverlauf löschen und neu starten?')) {
            $m.empty();
            welcome();
        }
        $c.addClass('closed');
    });

    $s.on('click', send);
    $i.on('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    });

    function welcome() {
        $m.html('<div class="bot">' + owc.welcome + '</div>');
        scroll();
    }

    function showTyping() {
        const $typing = $('<div class="bot typing">').html(
            '<span class="dot"></span><span class="dot"></span><span class="dot"></span>'
        );
        $m.append($typing);
        scroll();

        let dots = 0;
        typingInterval = setInterval(() => {
            dots = (dots + 1) % 4;
            $typing.find('.dot').eq(0).css('opacity', dots > 0 ? 1 : 0.3);
            $typing.find('.dot').eq(1).css('opacity', dots > 1 ? 1 : 0.3);
            $typing.find('.dot').eq(2).css('opacity', dots > 2 ? 1 : 0.3);
        }, 400);
    }

    function hideTyping() {
        if (typingInterval) clearInterval(typingInterval);
        $m.find('.typing').remove();
    }

    function send() {
        let msg = $i.val().trim();
        if (!msg) return;

        $m.append('<div class="user">Du: ' + msg + '</div>');
        $i.val(''); 
        scroll();
        showTyping();

        $.post(owc.ajax, {
            action: 'owc_chat',
            msg: msg,
            nonce: owc.nonce
        }, r => {
            hideTyping();
            let text = r.success ? r.data : 'Fehler';
            text = text.replace(/(https?:\/\/[^\s\)]+)/g, '<a href="$1" target="_blank" rel="noopener" style="color:#0073aa; text-decoration:underline;">$1</a>');
            $m.append('<div class="bot">' + text + '</div>');
            scroll();
        }).fail(() => {
            hideTyping();
            $m.append('<div class="bot error">Verbindung fehlgeschlagen.</div>');
            scroll();
        });
    }

    function scroll() { 
        $m.scrollTop($m[0].scrollHeight); 
    }

    setInterval(() => $b.toggleClass('pulse'), 3000);
});
