<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Session Replay workbench</title>
    <style>
        {{-- Enough real rules for the stylesheet to be stored once by hash instead of inside every snapshot. --}}
        @for ($i = 0; $i < 300; $i++) .u-{{ $i }} { margin: {{ $i }}px; padding: {{ $i % 7 }}px; color: hsl({{ $i }} 60% 40%); } @endfor
        body { font: 16px/1.5 system-ui; max-width: 40rem; margin: 3rem auto; padding: 0 1rem; }
        input, button { font: inherit; padding: .4rem .6rem; margin: .25rem 0; }
        .box { padding: 1rem; border: 1px solid #ccc; border-radius: .5rem; margin: 1rem 0; }
    </style>
</head>
<body>
    <h1>{{ ($second ?? false) ? 'Second page' : 'Workbench' }}</h1>
    <p><a href="{{ ($second ?? false) ? '/' : '/second' }}" id="nav">{{ ($second ?? false) ? 'Back' : 'Go to the second page' }}</a> · <a href="/session-replay">Recordings</a></p>

    <div class="box" wire:snapshot='{"data":{"secret":"not recorded"}}' x-data="{ open: false }">
        <label>Name <input id="name" type="text" placeholder="masked in the replay"></label><br>
        <label>Password <input id="password" type="password"></label>
        <p data-replay-mask>IBAN DE89 3704 0044 0532 0130 00 (masked text)</p>
        <p data-replay-block>This whole box is blocked.</p>
    </div>

    <button id="error" onclick="setTimeout(() => { throw new Error('Workbench exploded') })">Throw an error</button>
    <button id="log" onclick="console.error('Something logged', { code: 7 })">console.error</button>
    <button id="boom" onclick="fetch('/boom')">Server error</button>
    <button id="mark" onclick="SessionReplay.mark('Checkout started', { cart: 3 })">Custom marker</button>
    <button id="report" onclick="SessionReplay.send('Reported from the workbench').then((sent) => console.log('sent', sent))">Report a problem</button>
    <button id="add" onclick="document.getElementById('list').insertAdjacentHTML('beforeend', '<li wire:key=x>Item ' + Date.now() + '</li>')">Add a row</button>
    <ul id="list"></ul>

    @sessionReplay
</body>
</html>
