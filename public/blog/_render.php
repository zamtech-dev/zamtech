<?php
// Transforma o JSON de blocos do editor (formato do Editor.js, biblioteca
// pronta que substituiu nosso editor de blocos feito à mão) em HTML pronto
// pra exibir no artigo público. É usado tanto pelo artigo.php (público)
// quanto pelo admin (pra pré-visualizar). Fica fora da pasta admin/ de
// propósito, já que os dois lados precisam dele.
//
// Formato de um bloco (é exatamente o que o método editor.save() do
// Editor.js devolve, um por elemento do array "blocks"):
//   {"type":"header","data":{"text":"...","level":2|3|4}}
//   {"type":"paragraph","data":{"text":"texto com <b>, <i>, <a> permitidos"}}
//   {"type":"list","data":{"style":"ordered|unordered","items":[...]}}
//     — cada item pode ser uma string simples OU (na versão nova/aninhada
//     do List tool) um objeto {"content":"...","items":[...]} pra suportar
//     lista dentro de lista.
//   {"type":"quote","data":{"text":"...","caption":"..."}}
//   {"type":"delimiter","data":{}}
//   {"type":"imagemZamtech","data":{"url":"...","alt":"...","legenda":"..."}}
//     — essa é a NOSSA ferramenta customizada (não vem pronta no Editor.js),
//     que reaproveita o modal de upload/Unsplash/Pexels/corte que já
//     tínhamos.
//   {"type":"embed","data":{"service":"youtube|vimeo|codepen|instagram|twitter","source":"URL original colada"}}
//     — usa a ferramenta oficial @editorjs/embed. A gente ignora o iframe
//     que ela monta no navegador e remonta o embed aqui no servidor a
//     partir de "source" (a URL original), reaproveitando nossas próprias
//     funções de validação — assim não confiamos em HTML vindo do cliente.

/**
 * Permite só um punhado de tags inline seguras (negrito, itálico,
 * sublinhado, link, quebra de linha) e limpa tudo o mais. Isso evita que
 * HTML digitado/colado no editor vire um problema de segurança quando
 * for exibido pra qualquer visitante do blog.
 */
function sanitizarHtmlInline(string $html): string
{
    if (trim($html) === '') {
        return '';
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div>' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $permitidas = ['b', 'strong', 'i', 'em', 'u', 'a', 'br', 'ul', 'ol', 'li'];

    $limparNo = function (DOMNode $no) use (&$limparNo, $permitidas, $doc): void {
        $filhos = iterator_to_array($no->childNodes);
        foreach ($filhos as $filho) {
            if ($filho instanceof DOMElement) {
                $tag = strtolower($filho->tagName);
                if (!in_array($tag, $permitidas, true)) {
                    // script/style: apaga a tag INTEIRA com o conteúdo — não
                    // faz sentido um <script> virar texto solto no artigo.
                    // Qualquer outra tag não permitida (div, span, etc.) só
                    // perde a tag e mantém o texto de dentro.
                    if ($tag === 'script' || $tag === 'style') {
                        $no->removeChild($filho);
                        continue;
                    }
                    while ($filho->firstChild) {
                        $no->insertBefore($filho->firstChild, $filho);
                    }
                    $no->removeChild($filho);
                    continue;
                }

                if ($tag === 'a') {
                    $href = trim((string) $filho->getAttribute('href'));
                    $seguro = preg_match('#^(https?://|mailto:|tel:|/)#i', $href) === 1;
                    foreach (iterator_to_array($filho->attributes) as $attr) {
                        $filho->removeAttribute($attr->name);
                    }
                    if ($seguro) {
                        $filho->setAttribute('href', $href);
                        if (preg_match('#^https?://#i', $href)) {
                            $filho->setAttribute('target', '_blank');
                            $filho->setAttribute('rel', 'noopener noreferrer');
                        }
                    } else {
                        $filho->setAttribute('href', '#');
                    }
                } else {
                    foreach (iterator_to_array($filho->attributes) as $attr) {
                        $filho->removeAttribute($attr->name);
                    }
                }

                $limparNo($filho);
            }
        }
    };

    $raiz = $doc->getElementsByTagName('div')->item(0);
    if ($raiz) {
        $limparNo($raiz);
    }

    $resultado = '';
    if ($raiz) {
        foreach (iterator_to_array($raiz->childNodes) as $filho) {
            $resultado .= $doc->saveHTML($filho);
        }
    }

    return trim($resultado);
}

/**
 * Extrai o ID de vídeo do YouTube de qualquer formato de link comum.
 */
function extrairIdYoutube(string $url): ?string
{
    $padroes = [
        '#youtu\.be/([A-Za-z0-9_-]{6,})#',
        '#youtube\.com/watch\?v=([A-Za-z0-9_-]{6,})#',
        '#youtube\.com/embed/([A-Za-z0-9_-]{6,})#',
        '#youtube\.com/shorts/([A-Za-z0-9_-]{6,})#',
    ];
    foreach ($padroes as $padrao) {
        if (preg_match($padrao, $url, $m)) {
            return $m[1];
        }
    }
    return null;
}

function extrairIdVimeo(string $url): ?string
{
    if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m)) {
        return $m[1];
    }
    return null;
}

function extrairCodepen(string $url): ?array
{
    if (preg_match('#codepen\.io/([^/]+)/(?:pen|details|full)/([A-Za-z0-9]+)#', $url, $m)) {
        return ['usuario' => $m[1], 'slug' => $m[2]];
    }
    return null;
}

function validarUrlInstagram(string $url): bool
{
    return preg_match('#^https?://(www\.)?instagram\.com/(p|reel|tv)/[A-Za-z0-9_-]+/?#', $url) === 1;
}

function validarUrlTwitter(string $url): bool
{
    return preg_match('#^https?://(www\.)?(twitter|x)\.com/[A-Za-z0-9_]+/status/\d+#', $url) === 1;
}

/**
 * Monta o HTML de incorporação (embed) pra um provedor conhecido.
 * Retorna string vazia se a URL não bater com o formato esperado do
 * provedor — isso evita incorporar qualquer coisa que não seja de fato
 * um link válido daquele site.
 */
function gerarEmbedHtml(string $provider, string $url): string
{
    $url = trim($url);

    switch ($provider) {
        case 'youtube':
            $id = extrairIdYoutube($url);
            if (!$id) {
                return '';
            }
            $idEsc = htmlspecialchars($id, ENT_QUOTES);
            return '<div class="blog-embed blog-embed-video"><iframe src="https://www.youtube-nocookie.com/embed/' . $idEsc . '" title="Vídeo incorporado do YouTube" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>';

        case 'vimeo':
            $id = extrairIdVimeo($url);
            if (!$id) {
                return '';
            }
            $idEsc = htmlspecialchars($id, ENT_QUOTES);
            return '<div class="blog-embed blog-embed-video"><iframe src="https://player.vimeo.com/video/' . $idEsc . '" title="Vídeo incorporado do Vimeo" loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div>';

        case 'codepen':
            $dados = extrairCodepen($url);
            if (!$dados) {
                return '';
            }
            $usuario = htmlspecialchars($dados['usuario'], ENT_QUOTES);
            $slug = htmlspecialchars($dados['slug'], ENT_QUOTES);
            return '<div class="blog-embed blog-embed-codepen"><iframe src="https://codepen.io/' . $usuario . '/embed/' . $slug . '?default-tab=result" title="Pen incorporado do CodePen" loading="lazy" allow="clipboard-write" allowtransparency="true"></iframe></div>';

        case 'instagram':
            if (!validarUrlInstagram($url)) {
                return '';
            }
            $urlEsc = htmlspecialchars($url, ENT_QUOTES);
            return '<div class="blog-embed blog-embed-instagram"><blockquote class="instagram-media" data-instgrm-permalink="' . $urlEsc . '" data-instgrm-version="14"></blockquote></div>';

        case 'twitter':
            if (!validarUrlTwitter($url)) {
                return '';
            }
            $urlEsc = htmlspecialchars($url, ENT_QUOTES);
            return '<div class="blog-embed blog-embed-twitter"><blockquote class="twitter-tweet"><a href="' . $urlEsc . '"></a></blockquote></div>';

        default:
            return '';
    }
}

/**
 * True se pelo menos um bloco do artigo usa embeds de Instagram ou
 * Twitter/X — só nesse caso a página precisa carregar os scripts oficiais
 * desses sites (evita carregar script à toa em artigo que não usa).
 */
function artigoUsaEmbedSocial(array $blocks, string $provider): bool
{
    foreach ($blocks as $bloco) {
        $servico = strtolower((string) ($bloco['data']['service'] ?? ''));
        if (($bloco['type'] ?? '') === 'embed' && str_contains($servico, $provider)) {
            return true;
        }
    }
    return false;
}

/**
 * Renderiza um item de lista (e seus filhos aninhados, se houver) como
 * <li>...</li>, recursivamente. Aceita tanto um item "simples" (string)
 * quanto o formato novo do List tool ({"content":"...","items":[...]}).
 */
function renderizarItemLista($item): string
{
    if (is_array($item)) {
        $conteudo = sanitizarHtmlInline((string) ($item['content'] ?? ''));
        $filhos = $item['items'] ?? [];
    } else {
        $conteudo = sanitizarHtmlInline((string) $item);
        $filhos = [];
    }

    $html = '<li>' . $conteudo;
    if (is_array($filhos) && $filhos !== []) {
        // detecta se os filhos são ordenados ou não olhando se o próprio
        // item carrega essa info (o List tool aninhado não sempre repete o
        // "style" em cada nível — quando não vier, assume igual ao pai,
        // que é passado por parâmetro extra na chamada recursiva real).
        $html .= renderizarListaHtml($filhos, is_array($item) ? ($item['style'] ?? null) : null);
    }
    $html .= '</li>';

    return $html;
}

/**
 * Monta o <ul> ou <ol> completo de uma lista (nível superior ou aninhado).
 */
function renderizarListaHtml(array $items, ?string $estilo = null): string
{
    $tag = $estilo === 'ordered' ? 'ol' : 'ul';
    $html = "<{$tag}>";
    foreach ($items as $item) {
        $html .= renderizarItemLista($item);
    }
    $html .= "</{$tag}>";

    return $html;
}

/**
 * Ponto de entrada: recebe o array de blocos (já decodificado do JSON, no
 * formato que o Editor.js gera) e devolve o HTML completo do corpo do
 * artigo.
 */
function renderizarBlocosParaHtml(array $blocks): string
{
    $html = '';

    foreach ($blocks as $bloco) {
        $tipo = $bloco['type'] ?? '';
        $dados = is_array($bloco['data'] ?? null) ? $bloco['data'] : [];

        switch ($tipo) {
            case 'paragraph':
                $conteudo = sanitizarHtmlInline((string) ($dados['text'] ?? ''));
                if ($conteudo !== '') {
                    $html .= '<p>' . $conteudo . '</p>' . "\n";
                }
                break;

            case 'header':
                $nivel = (int) ($dados['level'] ?? 2);
                $nivel = in_array($nivel, [2, 3, 4], true) ? $nivel : 2;
                $texto = sanitizarHtmlInline((string) ($dados['text'] ?? ''));
                if ($texto !== '') {
                    $html .= "<h{$nivel}>{$texto}</h{$nivel}>\n";
                }
                break;

            case 'list':
                $items = is_array($dados['items'] ?? null) ? $dados['items'] : [];
                if ($items !== []) {
                    $estilo = ($dados['style'] ?? 'unordered') === 'ordered' ? 'ordered' : 'unordered';
                    $html .= renderizarListaHtml($items, $estilo) . "\n";
                }
                break;

            case 'quote':
                $texto = sanitizarHtmlInline((string) ($dados['text'] ?? ''));
                $legenda = sanitizarHtmlInline((string) ($dados['caption'] ?? ''));
                if ($texto !== '') {
                    $html .= '<blockquote class="blog-quote"><p>' . $texto . '</p>';
                    if ($legenda !== '') {
                        $html .= '<cite>' . $legenda . '</cite>';
                    }
                    $html .= "</blockquote>\n";
                }
                break;

            case 'delimiter':
                $html .= '<hr class="blog-delimiter" />' . "\n";
                break;

            case 'imagemZamtech':
                $src = htmlspecialchars((string) ($dados['url'] ?? ''), ENT_QUOTES);
                $alt = htmlspecialchars((string) ($dados['alt'] ?? ''), ENT_QUOTES);
                $legenda = htmlspecialchars((string) ($dados['legenda'] ?? ''), ENT_QUOTES);
                if ($src !== '') {
                    $html .= '<figure class="blog-figure"><img src="' . $src . '" alt="' . $alt . '" loading="lazy" />';
                    if ($legenda !== '') {
                        $html .= '<figcaption>' . $legenda . '</figcaption>';
                    }
                    $html .= "</figure>\n";
                }
                break;

            case 'embed':
                // Não confiamos no HTML/iframe que o Editor.js monta no
                // navegador — remontamos aqui a partir da URL original
                // ("source"), usando nossas próprias funções de validação.
                $servico = strtolower((string) ($dados['service'] ?? ''));
                $urlOriginal = (string) ($dados['source'] ?? '');
                $provider = '';
                foreach (['youtube', 'vimeo', 'codepen', 'instagram', 'twitter'] as $conhecido) {
                    if (str_contains($servico, $conhecido) || ($conhecido === 'twitter' && str_contains($servico, 'x-'))) {
                        $provider = $conhecido;
                        break;
                    }
                }
                $embedHtml = $provider !== '' ? gerarEmbedHtml($provider, $urlOriginal) : '';
                if ($embedHtml !== '') {
                    $html .= $embedHtml . "\n";
                }
                break;
        }
    }

    return $html;
}

/**
 * Calcula um tempo estimado de leitura (em minutos) a partir do HTML já
 * renderizado — conta palavras do texto puro a ~200 palavras/minuto.
 */
function calcularTempoLeitura(string $htmlRenderizado): int
{
    $texto = trim(strip_tags($htmlRenderizado));
    if ($texto === '') {
        return 1;
    }
    $palavras = preg_split('/\s+/', $texto);
    $totalPalavras = is_array($palavras) ? count($palavras) : 0;
    return max(1, (int) ceil($totalPalavras / 200));
}
