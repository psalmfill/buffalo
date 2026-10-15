<?php

use App\Rules\RichTextRule;
use App\Support\RichText\RichText;
use Illuminate\Support\Facades\Validator;

/*
| Phase 11B: the one server-side sanitiser and its plain text copy.
*/

test('XSS payloads are stripped', function (string $payload) {
    $html = RichText::sanitize($payload)->html ?? '';

    expect(strtolower($html))
        ->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('<svg')
        ->not->toContain('<iframe')
        ->not->toContain('<form')
        ->not->toContain('<object')
        ->not->toContain('<embed')
        ->not->toContain('<style')
        ->not->toContain('<math')
        ->not->toContain('javascript:')
        ->not->toContain('vbscript:')
        ->not->toContain('data:')
        ->not->toMatch('/\son[a-z]+\s*=/')
        ->not->toContain('style=')
        ->not->toContain('class=')
        ->not->toContain('srcdoc');
})->with([
    'script tag' => ['<p>Hi</p><script>alert(1)</script>'],
    'img onerror' => ['<img src=x onerror=alert(1)>'],
    'event handler on allowed tag' => ['<p onclick="alert(1)" onmouseover="x()">click</p>'],
    'javascript link' => ['<a href="javascript:alert(1)">x</a>'],
    'obfuscated javascript link' => ['<a href="  JaVaScRiPt:alert(1)">x</a><a href="java&#x09;script:alert(1)">y</a><a href="&#106;avascript:alert(1)">z</a>'],
    'vbscript link' => ['<a href="vbscript:msgbox(1)">x</a>'],
    'data url link' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>'],
    'svg with script' => ['<svg onload=alert(1)><script>alert(1)</script></svg><p>after</p>'],
    'svg use' => ['<svg><use href="data:image/svg+xml,<svg id=x xmlns=http://www.w3.org/2000/svg><image href=1 onerror=alert(1) /></svg>#x"></use></svg>'],
    'iframe' => ['<iframe src="https://example.com" srcdoc="<script>alert(1)</script>"></iframe>'],
    'form' => ['<form action="https://example.com"><input name=x><button formaction="javascript:alert(1)">go</button></form>'],
    'object and embed' => ['<object data="x.swf"></object><embed src="x.swf">'],
    'style tag and attribute' => ['<style>p{color:red}</style><p style="background:url(javascript:alert(1))" class="evil">x</p>'],
    'math' => ['<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>'],
    'nested and malformed' => ['<p><strong><scr<script>ipt>alert(1)</scr</script>ipt></strong></p><<img src=x onerror=alert(1)//>'],
    'unclosed tags' => ['<p onclick="x"><strong>bold <em>italic <a href="javascript:x">l'],
    'html comment trick' => ['<!--<img src="--><img src=x onerror=alert(1)//">'],
    'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
    'base href' => ['<base href="javascript:alert(1)//">'],
    'noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>">'],
]);

test('allowed formatting is kept and links are made safe', function () {
    $html = '<h3>Heading</h3><h4>Sub</h4><p><strong>B</strong> <em>I</em> <u>U</u><br>line</p>'
        .'<ul><li><p>one</p></li></ul><ol><li><p>two</p></li></ol><blockquote><p>q</p></blockquote>'
        .'<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'
        .'<p><a href="https://example.com/x" title="t">web</a> <a href="/incidents">here</a> <a href="mailto:ops@example.org">mail</a></p>';

    $clean = RichText::sanitize($html)->html ?? '';

    foreach (['<h3>Heading</h3>', '<h4>Sub</h4>', '<strong>B</strong>', '<em>I</em>', '<u>U</u>', '<br />', '<ul><li><p>one</p></li></ul>', '<ol>', '<blockquote>', '<table>', '<thead>', '<th>A</th>', '<td>1</td>'] as $kept) {
        expect($clean)->toContain($kept);
    }

    expect($clean)
        ->toContain('<a href="https://example.com/x" rel="noopener noreferrer" target="_blank">web</a>')
        ->toContain('<a href="/incidents" rel="noopener noreferrer" target="_blank">here</a>')
        ->toContain('href="mailto:')
        ->not->toContain('title=');
});

test('pasted Word and web formatting keeps bold and lists but drops fonts and colours', function () {
    $word = '<p class="MsoNormal" style="margin:0"><span style="font-family:Calibri;color:#FF0000;font-size:14pt"><b>Suspects</b> seen at <i>dawn</i></span><o:p></o:p></p>'
        .'<p class="MsoListParagraph"><font face="Arial" color="red">- not a list</font></p>'
        .'<ul style="list-style:square"><li><span style="color:blue">Tall man</span></li></ul><div>Plain div</div><h1>Title</h1>';

    $clean = RichText::sanitize($word);

    expect($clean->html ?? '')
        ->toContain('<strong>Suspects</strong>')
        ->toContain('<em>dawn</em>')
        ->toContain('<ul><li>Tall man</li></ul>')
        ->toContain('<p>Plain div</p>')
        ->toContain('<h3>Title</h3>')
        ->not->toContain('font')
        ->not->toContain('color')
        ->not->toContain('span')
        ->not->toContain('Calibri')
        ->and($clean->text ?? '')->toBe("Suspects seen at dawn\n\n- not a list\n\n- Tall man\n\nPlain div\n\nTitle");
});

test('the plain text copy turns blocks into line breaks and list items into "- " lines', function () {
    $value = RichText::sanitize('<p>Intro <strong>bold</strong></p><ul><li><p>one</p></li><li><p>two</p><ul><li><p>nested</p></li></ul></li></ul><p>a<br>b</p><table><tbody><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></tbody></table>');

    expect($value->text ?? null)->toBe("Intro bold\n\n- one\n- two\n  - nested\n\na\nb\n\nA | B\n1 | 2")
        ->and(RichText::sanitize('<p></p><p><br></p><ul><li></li></ul>'))->toBeNull()
        ->and(RichText::sanitize(null))->toBeNull();
});

test('plain text converts to rich text with paragraphs and line breaks kept, escaped', function () {
    $value = RichText::fromPlainText("Line one\nLine two\n\nSecond <b>para</b> & co");

    expect($value->html ?? null)->toBe('<p>Line one<br />Line two</p><p>Second &lt;b&gt;para&lt;/b&gt; &amp; co</p>')
        ->and($value->text ?? null)->toBe("Line one\nLine two\n\nSecond <b>para</b> & co");
});

test('oversized payloads are refused, and validation counts plain text', function () {
    config(['buffalo.rich_text.max_html_length' => 1000]);
    expect(fn () => RichText::sanitize(str_repeat('<p>x</p>', 200)))->toThrow(LengthException::class);
    config(['buffalo.rich_text.max_html_length' => 200000]);

    $validate = fn (mixed $value, RichTextRule $rule) => Validator::make(['field' => $value], ['field' => [$rule]])->errors()->first('field');

    // Markup does not count towards the limit; text does.
    expect($validate('<p><strong>'.str_repeat('a', 10).'</strong></p>', new RichTextRule(10)))->toBe('')
        ->and($validate('<p>'.str_repeat('a', 11).'</p>', new RichTextRule(10)))->toContain('may not be greater than 10 characters')
        // Formatting alone is empty.
        ->and($validate('<p><strong> </strong></p><p><br></p>', new RichTextRule(10, required: true)))->toBe('The field field is required.')
        ->and($validate('<p>short</p>', new RichTextRule(100, min: 10)))->toContain('at least 10')
        // The HTML may be at most html_factor (10) times the limit.
        ->and($validate(str_repeat('<p><strong>a</strong></p>', 10), new RichTextRule(10)))->toBe('The field is too long.')
        ->and($validate(['<p>x</p>'], new RichTextRule(10)))->toBe('The field must be text.');
});

test('combined values get one labelled block per source', function () {
    $combined = RichText::combine('<p>Ours</p>', 'Surviving record A', '<p>Theirs <script>x</script></p>', 'From merged record B');

    expect($combined->html ?? null)->toBe('<h4>Surviving record A</h4><p>Ours</p><h4>From merged record B</h4><p>Theirs </p>')
        ->and(RichText::combine('<p>Same</p>', 'A', '<p>Same</p>', 'B')->html ?? null)->toBe('<p>Same</p>')
        ->and(RichText::combine(null, 'A', '<p>Only theirs</p>', 'B')->html ?? null)->toBe('<h4>B</h4><p>Only theirs</p>');
});

test('sanitising is stable: sanitised HTML sanitises to itself', function () {
    $once = RichText::sanitize('<p class="x"><b>a</b> <a href="https://e.com">l</a></p><ul><li><p>i</p></li></ul><table><tbody><tr><td><p>c</p></td></tr></tbody></table>');
    $twice = RichText::sanitize($once->html ?? '');

    expect($twice?->html)->toBe($once?->html);
});
