<?php
require_once __DIR__ . '/_auth.php';
exigirLogin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$artigo = null;

$conn = conectarBanco();

if ($id > 0) {
    $stmt = $conn->prepare(
        'SELECT id, titulo, slug, categoria, resumo, conteudo_json, imagem_capa, imagem_capa_alt,
                meta_titulo, meta_descricao, status
         FROM blog_artigos WHERE id = ?'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $artigo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$artigo) {
        $conn->close();
        header('Location: /blog/admin/');
        exit;
    }
}

$categoriasDisponiveis = listarCategorias($conn);
$conn->close();

$dadosIniciais = [
    'id' => $artigo['id'] ?? null,
    'titulo' => $artigo['titulo'] ?? '',
    'slug' => $artigo['slug'] ?? '',
    'blocks' => $artigo ? (json_decode($artigo['conteudo_json'], true) ?: []) : [],
    'categoria' => $artigo['categoria'] ?? '',
    'imagem_capa' => $artigo['imagem_capa'] ?? '',
    'imagem_capa_alt' => $artigo['imagem_capa_alt'] ?? '',
    'resumo' => $artigo['resumo'] ?? '',
    'meta_titulo' => $artigo['meta_titulo'] ?? '',
    'meta_descricao' => $artigo['meta_descricao'] ?? '',
    'status' => $artigo['status'] ?? 'rascunho',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= $artigo ? 'Editando artigo' : 'Novo artigo' ?> — Blog Zamtech</title>
    <meta name="robots" content="noindex, nofollow" />
    <link rel="stylesheet" href="/blog/admin/assets/admin.css" />
    <link rel="stylesheet" href="/blog/admin/assets/editor.css" />
</head>
<body>
    <div class="admin-shell">
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <a href="/blog/admin/" class="btn btn-sm btn-outline">&larr; Voltar</a>
            </div>
            <nav class="admin-topbar-nav">
                <span id="status-salvamento" class="status-salvamento"></span>
                <button type="button" id="btn-salvar-rascunho" class="btn btn-sm btn-outline">Salvar rascunho</button>
                <button type="button" id="btn-publicar" class="btn btn-sm btn-primary">Publicar</button>
            </nav>
        </header>

        <main class="editor-shell">
            <div class="editor-coluna">
                <div class="capa-picker" id="capa-picker">
                    <div class="capa-preview" id="capa-preview">
                        <button type="button" class="btn btn-sm btn-outline" id="btn-escolher-capa">+ Imagem de capa</button>
                    </div>
                </div>

                <textarea
                    id="campo-titulo"
                    class="campo-titulo"
                    placeholder="Título do artigo"
                    rows="1"
                ></textarea>

                <div id="blocos-container" class="blocos-container"></div>

                <button type="button" class="btn-add-bloco-final" id="btn-add-final">+ Adicionar bloco</button>
            </div>

            <aside class="editor-lateral">
                <h3>Organização</h3>
                <div class="campo">
                    <label for="campo-categoria">Categoria</label>
                    <select id="campo-categoria">
                        <option value="">Sem categoria</option>
                        <?php foreach ($categoriasDisponiveis as $cat): ?>
                            <option value="<?= htmlspecialchars($cat, ENT_QUOTES) ?>" <?= ($dadosIniciais['categoria'] ?? '') === $cat ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat, ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="dica-upload">Pra criar ou excluir categorias, use a página <a href="/blog/admin/categorias.php" style="color: var(--color-primary);">Categorias</a>.</p>
                </div>

                <h3>SEO</h3>
                <div class="campo">
                    <label for="campo-resumo">Resumo (aparece na listagem do blog)</label>
                    <textarea id="campo-resumo" rows="3" maxlength="320"></textarea>
                </div>
                <div class="campo">
                    <label for="campo-meta-titulo">Título para o Google (até 70 caracteres)</label>
                    <input type="text" id="campo-meta-titulo" maxlength="70" />
                    <p class="dica-upload" id="contador-meta-titulo">0/70</p>
                </div>
                <div class="campo">
                    <label for="campo-meta-descricao">Descrição para o Google (até 160 caracteres)</label>
                    <textarea id="campo-meta-descricao" rows="3" maxlength="160"></textarea>
                    <p class="dica-upload" id="contador-meta-descricao">0/160</p>
                </div>
                <div class="campo">
                    <label>Como vai aparecer no Google</label>
                    <div class="preview-google" id="preview-google">
                        <div class="preview-google-site">
                            <span class="preview-google-favicon"></span>
                            <span>
                                <span class="preview-google-nome-site">Zamtech</span><br />
                                <span class="preview-google-url" id="preview-google-url">zamtech.com.br › blog</span>
                            </span>
                        </div>
                        <div class="preview-google-titulo" id="preview-google-titulo">Título do artigo</div>
                        <div class="preview-google-descricao" id="preview-google-descricao">A descrição do artigo aparece aqui conforme você escreve.</div>
                    </div>
                </div>
                <?php if ($artigo && $artigo['status'] === 'publicado'): ?>
                    <div class="campo">
                        <label>Link publicado</label>
                        <a href="/blog/<?= htmlspecialchars($artigo['slug'], ENT_QUOTES) ?>" target="_blank" style="color:var(--color-primary); font-size: var(--fs-sm); word-break: break-all;">
                            /blog/<?= htmlspecialchars($artigo['slug'], ENT_QUOTES) ?>
                        </a>
                    </div>
                <?php endif; ?>
            </aside>
        </main>
    </div>

    <!-- Menu "+" pra escolher tipo de bloco -->
    <div class="menu-flutuante" id="menu-add-bloco" hidden>
        <button data-tipo="paragraph">Texto</button>
        <button data-tipo="heading" data-nivel="2">Título (H2)</button>
        <button data-tipo="heading" data-nivel="3">Subtítulo (H3)</button>
        <button data-tipo="heading" data-nivel="4">Subtítulo menor (H4)</button>
        <button data-tipo="quote">Citação</button>
        <button data-tipo="delimiter">Divisor</button>
        <button data-tipo="image">Imagem</button>
        <button data-tipo="embed">Incorporar (YouTube, Instagram, X, Vimeo, CodePen)</button>
    </div>

    <!-- Barra de formatação flutuante (negrito, itálico, sublinhado, link, listas) -->
    <div class="barra-formatacao" id="barra-formatacao" hidden>
        <button data-cmd="bold" title="Negrito"><b>B</b></button>
        <button data-cmd="italic" title="Itálico"><i>I</i></button>
        <button data-cmd="underline" title="Sublinhado"><u>S</u></button>
        <button data-cmd="link" title="Link">Link</button>
        <button data-cmd="insertUnorderedList" title="Lista com marcadores">&#8226; Lista</button>
        <button data-cmd="insertOrderedList" title="Lista numerada">1. Lista</button>
    </div>

    <!-- Modal de imagem -->
    <div class="modal-overlay" id="modal-imagem" hidden>
        <div class="modal-caixa modal-imagem-caixa">
            <div class="modal-cabecalho">
                <div class="modal-abas">
                    <button type="button" class="aba-btn ativa" data-aba="enviar">Enviar imagem</button>
                    <button type="button" class="aba-btn" data-aba="banco">Já usadas</button>
                    <button type="button" class="aba-btn" data-aba="unsplash">Unsplash</button>
                    <button type="button" class="aba-btn" data-aba="pexels">Pexels</button>
                </div>
                <button type="button" class="modal-fechar" data-fechar-modal>&times;</button>
            </div>

            <div class="modal-corpo">
                <div class="aba-conteudo" data-aba-conteudo="enviar">
                    <div id="area-selecionar-arquivo">
                        <input type="file" id="input-arquivo-imagem" accept="image/png,image/jpeg,image/webp" />
                        <p class="dica-upload">PNG, JPEG ou WebP — até 12MB</p>
                    </div>
                    <div id="area-cortar-imagem" hidden>
                        <div class="crop-proporcoes" id="crop-proporcoes">
                            <button type="button" class="btn-proporcao ativa" data-proporcao="16/9">16:9</button>
                            <button type="button" class="btn-proporcao" data-proporcao="1/1">1:1</button>
                            <button type="button" class="btn-proporcao" data-proporcao="4/3">4:3</button>
                            <button type="button" class="btn-proporcao" data-proporcao="3/4">3:4</button>
                        </div>
                        <div class="crop-viewport" id="crop-viewport">
                            <img id="crop-imagem" alt="" />
                        </div>
                        <input type="range" id="crop-zoom" min="100" max="300" value="100" />
                        <p class="dica-upload">Arraste a imagem pra posicionar e use o controle pra dar zoom (a partir do centro)</p>
                    </div>
                </div>

                <div class="aba-conteudo" data-aba-conteudo="banco" hidden>
                    <div class="grade-banco-imagens" id="grade-banco-imagens">
                        <p class="dica-upload">Carregando...</p>
                    </div>
                </div>

                <div class="aba-conteudo" data-aba-conteudo="unsplash" hidden>
                    <div class="busca-imagem-externa">
                        <input type="text" id="busca-unsplash" placeholder="Buscar no Unsplash (ex: fibra óptica, internet, família)" />
                        <button type="button" class="btn btn-sm btn-outline" id="btn-buscar-unsplash">Buscar</button>
                    </div>
                    <div class="grade-banco-imagens grade-imagens-externas" id="grade-unsplash"></div>
                </div>

                <div class="aba-conteudo" data-aba-conteudo="pexels" hidden>
                    <div class="busca-imagem-externa">
                        <input type="text" id="busca-pexels" placeholder="Buscar no Pexels" />
                        <button type="button" class="btn btn-sm btn-outline" id="btn-buscar-pexels">Buscar</button>
                    </div>
                    <div class="grade-banco-imagens grade-imagens-externas" id="grade-pexels"></div>
                </div>

                <div class="campo" style="margin-top: 16px;">
                    <label for="campo-alt-imagem">Texto alternativo (alt) — obrigatório</label>
                    <input type="text" id="campo-alt-imagem" placeholder="Descreva a imagem em poucas palavras" />
                </div>
                <div class="campo">
                    <label for="campo-legenda-imagem">Legenda (opcional)</label>
                    <input type="text" id="campo-legenda-imagem" placeholder="Aparece abaixo da imagem" />
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="btn btn-outline" data-fechar-modal>Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-imagem">Usar esta imagem</button>
            </div>
        </div>
    </div>

    <!-- Modal de incorporar (embed) -->
    <div class="modal-overlay" id="modal-embed" hidden>
        <div class="modal-caixa">
            <div class="modal-cabecalho">
                <h3>Incorporar conteúdo</h3>
                <button type="button" class="modal-fechar" data-fechar-modal>&times;</button>
            </div>
            <div class="modal-corpo">
                <div class="campo">
                    <label for="campo-url-embed">Cole o link do YouTube, Instagram, X (Twitter), Vimeo ou CodePen</label>
                    <input type="text" id="campo-url-embed" placeholder="https://..." />
                </div>
                <div id="preview-embed"></div>
                <p class="alerta alerta-erro" id="erro-embed" hidden></p>
            </div>
            <div class="modal-rodape">
                <button type="button" class="btn btn-outline" data-fechar-modal>Cancelar</button>
                <button type="button" class="btn btn-primary" id="btn-confirmar-embed">Inserir</button>
            </div>
        </div>
    </div>

    <script>
        window.ARTIGO_INICIAL = <?= json_encode($dadosIniciais, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        window.CSRF_TOKEN = <?= json_encode(pegarTokenCsrf()) ?>;
    </script>
    <script src="/blog/admin/assets/editor.js"></script>
</body>
</html>
