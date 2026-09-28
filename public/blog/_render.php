<?php
// Transforma o JSON de blocos do editor em HTML pronto pra exibir no
// artigo público. É usado tanto pelo artigo.php (público) quanto pelo
// admin (pra pré-visualizar). Fica fora da pasta admin/ de propósito,
// já que os dois lados precisam dele.
//
// Formato de um bloco (array associativo), campo "type" define o resto:
//   {"type":"paragraph","html":"texto com <b>, <i>, <a> permitidos"}
//   {"type":"heading","level":2|3|4,"text":"..."}
//   {"type":"quote","text":"...","legenda":"..."}
//   {"type":"delimiter"}
//   {"type":"image","src":"/assets/img/blog/x.webp","alt":"...","legenda":"..."}
//   {"type":"embed","provider":"youtube|vimeo|codepen|instagram|twitter","url":"..."}

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
        if (($bloco['type'] ?? '') === 'embed' && ($bloco['provider'] ?? '') === $provider) {
            return true;
        }
    }
    return false;
}

/**
 * Ponto de entrada: recebe o array de blocos (já decodificado do JSON) e
 * devolve o HTML completo do corpo do artigo.
 */
function renderizarBlocosParaHtml(array $blocks): string
{
    $html = '';

    foreach ($blocks as $bloco) {
        $tipo = $bloco['type'] ?? '';

        switch ($tipo) {
            case 'paragraph':
                $conteudo = sanitizarHtmlInline((string) ($bloco['html'] ?? ''));
                if ($conteudo !== '') {
                    // se o bloco virou uma lista (bullet/numerada) não embrulha
                    // em <p> — <ul>/<ol> dentro de <p> é HTML inválido e o
                    // navegador fecha a tag sozinho de um jeito estranho.
                    if (preg_match('/^<(ul|ol)[ >]/i', $conteudo) === 1) {
                        $html .= $conteudo . "\n";
                    } else {
                        $html .= '<p>' . $conteudo . '</p>' . "\n";
                    }
                }
                break;

            case 'heading':
                $nivel = (int) ($bloco['level'] ?? 2);
                $nivel = in_array($nivel, [2, 3, 4], true) ? $nivel : 2;
                $texto = htmlspecialchars((string) ($bloco['text'] ?? ''), ENT_QUOTES);
                if ($texto !== '') {
                    $html .= "<h{$nivel}>{$texto}</h{$nivel}>\n";
                }
                break;

            case 'quote':
                $texto = htmlspecialchars((string) ($bloco['text'] ?? ''), ENT_QUOTES);
                $legenda = htmlspecialchars((string) ($bloco['legenda'] ?? ''), ENT_QUOTES);
                if ($texto !== '') {
                    $html .= '<blockquote class="blog-quote"><p>' . nl2br($texto) . '</p>';
                    if ($legenda !== '') {
                        $html .= '<cite>' . $legenda . '</cite>';
                    }
                    $html .= "</blockquote>\n";
                }
                break;

            case 'delimiter':
                $html .= '<hr class="blog-delimiter" />' . "\n";
                break;

            case 'image':
                $src = htmlspecialchars((string) ($bloco['src'] ?? ''), ENT_QUOTES);
                $alt = htmlspecialchars((string) ($bloco['alt'] ?? ''), ENT_QUOTES);
                $legenda = htmlspecialchars((string) ($bloco['legenda'] ?? ''), ENT_QUOTES);
                if ($src !== '') {
                    $html .= '<figure class="blog-figure"><img src="' . $src . '" alt="' . $alt . '" loading="lazy" />';
                    if ($legenda !== '') {
                        $html .= '<figcaption>' . $legenda . '</figcaption>';
                    }
                    $html .= "</figure>\n";
                }
                break;

            case 'embed':
                $provider = (string) ($bloco['provider'] ?? '');
                $url = (string) ($bloco['url'] ?? '');
                $embedHtml = gerarEmbedHtml($provider, $url);
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
