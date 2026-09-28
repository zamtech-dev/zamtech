(function () {
    'use strict';

    // Este arquivo ficou bem mais curto do que era antes. O motivo: até a
    // rodada passada, TUDO aqui era feito à mão — os blocos de texto, a
    // barra de formatação flutuante, o menu de "+", o corte de imagem.
    // Migramos o "corpo do artigo" pro Editor.js (editorjs.io), uma
    // biblioteca pronta e testada por milhares de sites, que já resolve
    // sozinha os problemas que a gente ficava caçando um por um (cursor
    // pulando, lista com recuo errado, barra flutuante não aparecendo).
    //
    // O que continua sendo nosso, do jeito que sempre foi: a capa do
    // artigo, os campos de SEO/categoria, o preview do Google, o
    // autosave — e o modal de imagem (upload/"já usadas"/Unsplash/Pexels/
    // corte), que agora é chamado de dentro de uma ferramenta customizada
    // do Editor.js (a classe FerramentaImagem, mais abaixo) em vez de um
    // clique no menu "+" do editor antigo.

    var campoTitulo = document.getElementById('campo-titulo');
    var campoCategoria = document.getElementById('campo-categoria');
    var campoResumo = document.getElementById('campo-resumo');
    var campoMetaTitulo = document.getElementById('campo-meta-titulo');
    var campoMetaDescricao = document.getElementById('campo-meta-descricao');
    var capaPreview = document.getElementById('capa-preview');
    var statusSalvamento = document.getElementById('status-salvamento');

    var estado = {
        imagemCapa: window.ARTIGO_INICIAL.imagem_capa || '',
        imagemCapaAlt: window.ARTIGO_INICIAL.imagem_capa_alt || '',
        artigoId: window.ARTIGO_INICIAL.id || null,
        statusAtual: window.ARTIGO_INICIAL.status || 'rascunho',
    };

    // ---------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------

    function escaparHtml(texto) {
        var div = document.createElement('div');
        div.textContent = texto || '';
        return div.innerHTML;
    }

    function ajustarAlturaTitulo() {
        campoTitulo.style.height = 'auto';
        campoTitulo.style.height = campoTitulo.scrollHeight + 'px';
    }
    campoTitulo.addEventListener('input', ajustarAlturaTitulo);

    // ---------------------------------------------------------------
    // Capa
    // ---------------------------------------------------------------

    function renderizarCapa() {
        if (estado.imagemCapa) {
            capaPreview.innerHTML =
                '<img src="' + estado.imagemCapa + '" alt="" />' +
                '<button type="button" class="btn btn-sm btn-outline btn-trocar-capa" id="btn-trocar-capa">Trocar capa</button>';
            document.getElementById('btn-trocar-capa').addEventListener('click', function () {
                abrirModalImagem(function (dados) {
                    estado.imagemCapa = dados.url;
                    estado.imagemCapaAlt = dados.alt;
                    renderizarCapa();
                }, estado.imagemCapaAlt);
            });
        } else {
            capaPreview.innerHTML = '<button type="button" class="btn btn-sm btn-outline" id="btn-escolher-capa">+ Imagem de capa</button>';
            document.getElementById('btn-escolher-capa').addEventListener('click', function () {
                abrirModalImagem(function (dados) {
                    estado.imagemCapa = dados.url;
                    estado.imagemCapaAlt = dados.alt;
                    renderizarCapa();
                });
            });
        }
    }

    // ---------------------------------------------------------------
    // Modal de imagem (upload com corte/zoom, banco já usado, ou
    // Unsplash/Pexels) — o mesmo modal serve pra capa E pros blocos de
    // imagem do corpo do artigo (ferramenta customizada mais abaixo).
    // Chame abrirModalImagem(callback, altAtual?) de qualquer lugar; o
    // callback recebe { url, alt, legenda }.
    // ---------------------------------------------------------------

    var modalImagem = document.getElementById('modal-imagem');
    var inputArquivo = document.getElementById('input-arquivo-imagem');
    var areaSelecionarArquivo = document.getElementById('area-selecionar-arquivo');
    var areaCortarImagem = document.getElementById('area-cortar-imagem');
    var cropViewport = document.getElementById('crop-viewport');
    var cropImagem = document.getElementById('crop-imagem');
    var cropZoom = document.getElementById('crop-zoom');
    var campoAltImagem = document.getElementById('campo-alt-imagem');
    var campoLegendaImagem = document.getElementById('campo-legenda-imagem');
    var gradeBancoImagens = document.getElementById('grade-banco-imagens');

    var callbackImagemAtual = null;
    var cropEstado = { escala: 1, x: 0, y: 0, arrastando: false, inicioX: 0, inicioY: 0 };
    var urlBancoSelecionada = null;
    var imagemExternaSelecionada = null; // { completa, download_location, credito } — Unsplash/Pexels

    function resetarModalImagem() {
        areaSelecionarArquivo.hidden = false;
        areaCortarImagem.hidden = true;
        inputArquivo.value = '';
        campoAltImagem.value = '';
        campoLegendaImagem.value = '';
        urlBancoSelecionada = null;
        imagemExternaSelecionada = null;
        cropEstado = { escala: 1, x: 0, y: 0, arrastando: false, inicioX: 0, inicioY: 0 };
        cropZoom.value = 100;
        cropViewport.style.aspectRatio = '16 / 9';
        document.querySelectorAll('.btn-proporcao').forEach(function (b) { b.classList.remove('ativa'); });
        document.querySelector('.btn-proporcao[data-proporcao="16/9"]').classList.add('ativa');
        document.querySelectorAll('.aba-btn').forEach(function (b) { b.classList.remove('ativa'); });
        document.querySelector('.aba-btn[data-aba="enviar"]').classList.add('ativa');
        document.querySelectorAll('.aba-conteudo').forEach(function (a) { a.hidden = true; });
        document.querySelector('[data-aba-conteudo="enviar"]').hidden = false;
    }

    function abrirModalImagem(callback, altAtual) {
        callbackImagemAtual = callback;
        resetarModalImagem();
        if (altAtual) {
            campoAltImagem.value = altAtual;
        }
        modalImagem.hidden = false;
    }

    function fecharTodosOsModais() {
        modalImagem.hidden = true;
    }

    document.querySelectorAll('[data-fechar-modal]').forEach(function (botao) {
        botao.addEventListener('click', fecharTodosOsModais);
    });

    document.querySelectorAll('.aba-btn').forEach(function (aba) {
        aba.addEventListener('click', function () {
            document.querySelectorAll('.aba-btn').forEach(function (b) { b.classList.remove('ativa'); });
            aba.classList.add('ativa');
            var nomeAba = aba.getAttribute('data-aba');
            document.querySelectorAll('.aba-conteudo').forEach(function (a) { a.hidden = true; });
            document.querySelector('[data-aba-conteudo="' + nomeAba + '"]').hidden = false;

            if (nomeAba === 'banco') {
                carregarBancoDeImagens();
            }
        });
    });

    function carregarBancoDeImagens() {
        gradeBancoImagens.innerHTML = '<p class="dica-upload">Carregando...</p>';
        fetch('/blog/admin/ajax/listar-imagens.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (dados) {
                if (!dados.sucesso || !dados.imagens.length) {
                    gradeBancoImagens.innerHTML = '<p class="dica-upload">Nenhuma imagem enviada ainda.</p>';
                    return;
                }
                gradeBancoImagens.innerHTML = '';
                dados.imagens.forEach(function (img) {
                    var el = document.createElement('img');
                    el.src = img.url;
                    el.addEventListener('click', function () {
                        gradeBancoImagens.querySelectorAll('img').forEach(function (i) { i.classList.remove('selecionada'); });
                        el.classList.add('selecionada');
                        urlBancoSelecionada = img.url;
                        imagemExternaSelecionada = null;
                    });
                    gradeBancoImagens.appendChild(el);
                });
            })
            .catch(function () {
                gradeBancoImagens.innerHTML = '<p class="dica-upload">Erro ao carregar as imagens.</p>';
            });
    }

    // ---------------------------------------------------------------
    // Banco de imagens externo (Unsplash / Pexels) — busca no servidor
    // (que fala com a API deles) e, se escolher uma, baixa e converte
    // pra WebP no nosso próprio servidor (não fica "pendurado" na URL
    // externa).
    // ---------------------------------------------------------------

    function buscarImagensExternas(fonte, termo, grade) {
        grade.innerHTML = '<p class="dica-upload">Buscando...</p>';
        fetch('/blog/admin/ajax/buscar-imagens-externas.php?fonte=' + fonte + '&q=' + encodeURIComponent(termo), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (r) { return r.json(); })
            .then(function (dados) {
                if (!dados.sucesso) {
                    grade.innerHTML = '<p class="dica-upload">' + escaparHtml(dados.mensagem || 'Erro na busca.') + '</p>';
                    return;
                }
                if (!dados.imagens.length) {
                    grade.innerHTML = '<p class="dica-upload">Nada encontrado. Tenta outra palavra.</p>';
                    return;
                }
                grade.innerHTML = '';
                dados.imagens.forEach(function (img) {
                    var item = document.createElement('div');
                    item.className = 'item-imagem-externa';
                    item.innerHTML =
                        '<img src="' + img.miniatura + '" alt="" />' +
                        '<span class="credito-imagem-externa">' + escaparHtml(img.credito) + '</span>';
                    item.addEventListener('click', function () {
                        grade.querySelectorAll('.item-imagem-externa').forEach(function (i) { i.classList.remove('selecionada'); });
                        item.classList.add('selecionada');
                        imagemExternaSelecionada = {
                            completa: img.completa,
                            download_location: img.download_location,
                            credito: img.credito,
                        };
                        urlBancoSelecionada = null;
                        if (!campoLegendaImagem.value.trim()) {
                            campoLegendaImagem.value = img.credito;
                        }
                    });
                    grade.appendChild(item);
                });
            })
            .catch(function () {
                grade.innerHTML = '<p class="dica-upload">Erro de conexão na busca.</p>';
            });
    }

    function ativarBuscaExterna(fonte, idInput, idBotao, idGrade) {
        var input = document.getElementById(idInput);
        var botao = document.getElementById(idBotao);
        var grade = document.getElementById(idGrade);

        function dispararBusca() {
            var termo = input.value.trim();
            if (termo) {
                buscarImagensExternas(fonte, termo, grade);
            }
        }

        botao.addEventListener('click', dispararBusca);
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                dispararBusca();
            }
        });
    }

    ativarBuscaExterna('unsplash', 'busca-unsplash', 'btn-buscar-unsplash', 'grade-unsplash');
    ativarBuscaExterna('pexels', 'busca-pexels', 'btn-buscar-pexels', 'grade-pexels');

    inputArquivo.addEventListener('change', function () {
        var arquivo = inputArquivo.files[0];
        if (!arquivo) {
            return;
        }
        var leitor = new FileReader();
        leitor.onload = function (ev) {
            cropImagem.src = ev.target.result;
            areaSelecionarArquivo.hidden = true;
            areaCortarImagem.hidden = false;
            cropEstado = { escala: 1, x: 0, y: 0, arrastando: false, inicioX: 0, inicioY: 0 };
            cropZoom.value = 100;
            cropImagem.onload = function () {
                centralizarCrop();
            };
        };
        leitor.readAsDataURL(arquivo);
    });

    function centralizarCrop() {
        var vpRect = cropViewport.getBoundingClientRect();
        var larguraBase = vpRect.width;
        var alturaNatural = cropImagem.naturalHeight * (larguraBase / cropImagem.naturalWidth);
        cropImagem.style.width = larguraBase + 'px';
        cropImagem.style.height = alturaNatural + 'px';
        cropEstado.x = 0;
        cropEstado.y = Math.min(0, (vpRect.height - alturaNatural) / 2);
        aplicarTransformCrop();
    }

    function aplicarTransformCrop() {
        cropImagem.style.transform =
            'translate(' + cropEstado.x + 'px, ' + cropEstado.y + 'px) scale(' + cropEstado.escala + ')';
        cropImagem.style.transformOrigin = 'top left';
    }

    cropZoom.addEventListener('input', function () {
        // zoom a partir do CENTRO do viewport (não do canto superior
        // esquerdo): recalcula x/y junto com a escala pra manter o ponto
        // que está embaixo do centro sempre no centro.
        var vpRect = cropViewport.getBoundingClientRect();
        var centroX = vpRect.width / 2;
        var centroY = vpRect.height / 2;
        var escalaAntiga = cropEstado.escala;
        var escalaNova = parseInt(cropZoom.value, 10) / 100;

        cropEstado.x = centroX - (centroX - cropEstado.x) * (escalaNova / escalaAntiga);
        cropEstado.y = centroY - (centroY - cropEstado.y) * (escalaNova / escalaAntiga);
        cropEstado.escala = escalaNova;
        aplicarTransformCrop();
    });

    // Botões de proporção (16:9, 1:1, 4:3, 3:4) do modal de corte.
    document.querySelectorAll('.btn-proporcao').forEach(function (botao) {
        botao.addEventListener('click', function () {
            document.querySelectorAll('.btn-proporcao').forEach(function (b) { b.classList.remove('ativa'); });
            botao.classList.add('ativa');
            cropViewport.style.aspectRatio = botao.getAttribute('data-proporcao');
            cropZoom.value = 100;
            requestAnimationFrame(function () {
                centralizarCrop();
            });
        });
    });

    cropViewport.addEventListener('mousedown', function (ev) {
        cropEstado.arrastando = true;
        cropEstado.inicioX = ev.clientX - cropEstado.x;
        cropEstado.inicioY = ev.clientY - cropEstado.y;
    });
    window.addEventListener('mousemove', function (ev) {
        if (!cropEstado.arrastando) {
            return;
        }
        cropEstado.x = ev.clientX - cropEstado.inicioX;
        cropEstado.y = ev.clientY - cropEstado.inicioY;
        aplicarTransformCrop();
    });
    window.addEventListener('mouseup', function () {
        cropEstado.arrastando = false;
    });
    cropViewport.addEventListener('touchstart', function (ev) {
        var t = ev.touches[0];
        cropEstado.arrastando = true;
        cropEstado.inicioX = t.clientX - cropEstado.x;
        cropEstado.inicioY = t.clientY - cropEstado.y;
    });
    cropViewport.addEventListener('touchmove', function (ev) {
        if (!cropEstado.arrastando) {
            return;
        }
        var t = ev.touches[0];
        cropEstado.x = t.clientX - cropEstado.inicioX;
        cropEstado.y = t.clientY - cropEstado.inicioY;
        aplicarTransformCrop();
    });
    cropViewport.addEventListener('touchend', function () {
        cropEstado.arrastando = false;
    });

    function gerarBlobRecortado(callback) {
        var vpRect = cropViewport.getBoundingClientRect();
        var canvas = document.createElement('canvas');
        var larguraSaida = 1600;
        var alturaSaida = Math.round(larguraSaida * (vpRect.height / vpRect.width));
        canvas.width = larguraSaida;
        canvas.height = alturaSaida;
        var ctx = canvas.getContext('2d');

        var fatorEscalaSaida = larguraSaida / vpRect.width;
        var imgLargura = cropImagem.getBoundingClientRect().width * cropEstado.escala;
        var imgAltura = cropImagem.getBoundingClientRect().height * cropEstado.escala;

        ctx.drawImage(
            cropImagem,
            0, 0, cropImagem.naturalWidth, cropImagem.naturalHeight,
            cropEstado.x * fatorEscalaSaida,
            cropEstado.y * fatorEscalaSaida,
            imgLargura * fatorEscalaSaida,
            imgAltura * fatorEscalaSaida
        );

        canvas.toBlob(function (blob) {
            callback(blob);
        }, 'image/jpeg', 0.92);
    }

    document.getElementById('btn-confirmar-imagem').addEventListener('click', function () {
        var alt = campoAltImagem.value.trim();
        if (!alt) {
            alert('Escreva o texto alternativo (alt) da imagem.');
            return;
        }

        if (urlBancoSelecionada) {
            callbackImagemAtual({ url: urlBancoSelecionada, alt: alt, legenda: campoLegendaImagem.value.trim() });
            fecharTodosOsModais();
            return;
        }

        if (imagemExternaSelecionada) {
            var botaoExterna = document.getElementById('btn-confirmar-imagem');
            botaoExterna.disabled = true;
            botaoExterna.textContent = 'Baixando...';

            fetch('/blog/admin/ajax/importar-imagem-externa.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    url: imagemExternaSelecionada.completa,
                    download_location: imagemExternaSelecionada.download_location,
                    alt: alt,
                    csrf: window.CSRF_TOKEN,
                }),
            })
                .then(function (r) { return r.json(); })
                .then(function (dados) {
                    botaoExterna.disabled = false;
                    botaoExterna.textContent = 'Usar esta imagem';
                    if (dados.sucesso) {
                        callbackImagemAtual({ url: dados.url, alt: alt, legenda: campoLegendaImagem.value.trim() });
                        fecharTodosOsModais();
                    } else {
                        alert(dados.mensagem || 'Não deu pra baixar essa imagem.');
                    }
                })
                .catch(function () {
                    botaoExterna.disabled = false;
                    botaoExterna.textContent = 'Usar esta imagem';
                    alert('Erro de conexão ao baixar a imagem.');
                });
            return;
        }

        if (!cropImagem.src || areaCortarImagem.hidden) {
            alert('Selecione uma imagem primeiro.');
            return;
        }

        var botao = document.getElementById('btn-confirmar-imagem');
        botao.disabled = true;
        botao.textContent = 'Enviando...';

        gerarBlobRecortado(function (blob) {
            var formData = new FormData();
            formData.append('imagem', blob, 'imagem.jpg');
            formData.append('alt', alt);
            formData.append('csrf', window.CSRF_TOKEN);

            fetch('/blog/admin/ajax/upload-imagem.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then(function (r) { return r.json(); })
                .then(function (dados) {
                    botao.disabled = false;
                    botao.textContent = 'Usar esta imagem';
                    if (dados.sucesso) {
                        callbackImagemAtual({ url: dados.url, alt: alt, legenda: campoLegendaImagem.value.trim() });
                        fecharTodosOsModais();
                    } else {
                        alert(dados.mensagem || 'Não deu pra enviar a imagem.');
                    }
                })
                .catch(function () {
                    botao.disabled = false;
                    botao.textContent = 'Usar esta imagem';
                    alert('Erro de conexão ao enviar a imagem.');
                });
        });
    });

    // ---------------------------------------------------------------
    // Preview de como o artigo aparece no Google (tipo o Yoast) +
    // contador de caracteres do título/descrição de SEO
    // ---------------------------------------------------------------

    var previewGoogleUrl = document.getElementById('preview-google-url');
    var previewGoogleTitulo = document.getElementById('preview-google-titulo');
    var previewGoogleDescricao = document.getElementById('preview-google-descricao');
    var contadorMetaTitulo = document.getElementById('contador-meta-titulo');
    var contadorMetaDescricao = document.getElementById('contador-meta-descricao');

    function slugPreview(texto) {
        return (
            (texto || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[̀-ͯ]/g, '')
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
        ) || 'artigo';
    }

    function atualizarPreviewGoogle() {
        var tituloSeo = campoMetaTitulo.value.trim() || campoTitulo.value.trim() || 'Título do artigo';
        var descricaoSeo = campoMetaDescricao.value.trim() || campoResumo.value.trim() || 'A descrição do artigo aparece aqui conforme você escreve.';
        var slugAtual = window.ARTIGO_INICIAL.slug || slugPreview(campoTitulo.value.trim());

        previewGoogleUrl.textContent = 'zamtech.com.br › blog › ' + slugAtual;
        previewGoogleTitulo.textContent = tituloSeo.length > 60 ? tituloSeo.slice(0, 60) + '…' : tituloSeo;
        previewGoogleDescricao.textContent = descricaoSeo.length > 160 ? descricaoSeo.slice(0, 160) + '…' : descricaoSeo;

        contadorMetaTitulo.textContent = campoMetaTitulo.value.length + '/70';
        contadorMetaTitulo.style.color = campoMetaTitulo.value.length > 60 ? 'var(--color-danger)' : 'var(--color-gray-medium)';
        contadorMetaDescricao.textContent = campoMetaDescricao.value.length + '/160';
        contadorMetaDescricao.style.color = campoMetaDescricao.value.length > 155 ? 'var(--color-danger)' : 'var(--color-gray-medium)';
    }

    campoMetaTitulo.addEventListener('input', atualizarPreviewGoogle);
    campoMetaDescricao.addEventListener('input', atualizarPreviewGoogle);
    campoTitulo.addEventListener('input', atualizarPreviewGoogle);
    campoResumo.addEventListener('input', atualizarPreviewGoogle);

    // ---------------------------------------------------------------
    // Ferramenta customizada de imagem pro Editor.js. É a ponte entre o
    // Editor.js (que só sabe desenhar blocos e chamar render()/save()) e
    // o nosso modal de imagem de sempre (upload/banco/Unsplash/Pexels/
    // corte), que continua sendo 100% nosso código.
    // ---------------------------------------------------------------

    function FerramentaImagem(opcoes) {
        this.data = {
            url: (opcoes.data && opcoes.data.url) || '',
            alt: (opcoes.data && opcoes.data.alt) || '',
            legenda: (opcoes.data && opcoes.data.legenda) || '',
        };
        this.wrapper = null;
    }

    FerramentaImagem.toolbox = {
        title: 'Imagem',
        icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><path d="M14.5 1.5h-12a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-10a1 1 0 0 0-1-1zm-9 2.25a1.25 1.25 0 1 1 0 2.5 1.25 1.25 0 0 1 0-2.5zM3 12l3.25-3.5L8.5 11l3.75-4.25L14 12H3z" fill="currentColor"/></svg>',
    };

    FerramentaImagem.prototype.render = function () {
        this.wrapper = document.createElement('div');
        this.wrapper.className = 'ce-imagem-zamtech';
        this._desenhar();
        return this.wrapper;
    };

    FerramentaImagem.prototype._desenhar = function () {
        var self = this;
        this.wrapper.innerHTML = '';

        if (this.data.url) {
            var img = document.createElement('img');
            img.src = this.data.url;
            img.alt = this.data.alt || '';
            img.className = 'ce-imagem-zamtech-img';

            var legenda = document.createElement('input');
            legenda.type = 'text';
            legenda.placeholder = 'Legenda (opcional)';
            legenda.value = this.data.legenda || '';
            legenda.className = 'ce-imagem-zamtech-legenda';
            legenda.addEventListener('input', function () {
                self.data.legenda = legenda.value;
            });

            var trocar = document.createElement('button');
            trocar.type = 'button';
            trocar.className = 'ce-imagem-zamtech-trocar btn btn-sm btn-outline';
            trocar.textContent = 'Trocar imagem';
            trocar.addEventListener('click', function () {
                abrirModalImagem(function (dados) {
                    self.data.url = dados.url;
                    self.data.alt = dados.alt;
                    self.data.legenda = dados.legenda || '';
                    self._desenhar();
                }, self.data.alt);
            });

            this.wrapper.appendChild(img);
            this.wrapper.appendChild(legenda);
            this.wrapper.appendChild(trocar);
        } else {
            var botaoVazio = document.createElement('button');
            botaoVazio.type = 'button';
            botaoVazio.className = 'ce-imagem-zamtech-vazio';
            botaoVazio.textContent = '+ Adicionar imagem';
            botaoVazio.addEventListener('click', function () {
                abrirModalImagem(function (dados) {
                    self.data.url = dados.url;
                    self.data.alt = dados.alt;
                    self.data.legenda = dados.legenda || '';
                    self._desenhar();
                });
            });
            this.wrapper.appendChild(botaoVazio);
        }
    };

    FerramentaImagem.prototype.save = function () {
        return this.data;
    };

    FerramentaImagem.prototype.validate = function (dadosSalvos) {
        return !!dadosSalvos.url;
    };

    FerramentaImagem.sanitize = {
        url: false,
        alt: {},
        legenda: {},
    };

    // ---------------------------------------------------------------
    // Inicialização do Editor.js
    // ---------------------------------------------------------------

    function preencherCamposDoArtigo() {
        var dados = window.ARTIGO_INICIAL;
        campoTitulo.value = dados.titulo || '';
        ajustarAlturaTitulo();
        campoCategoria.value = dados.categoria || '';
        campoResumo.value = dados.resumo || '';
        campoMetaTitulo.value = dados.meta_titulo || '';
        campoMetaDescricao.value = dados.meta_descricao || '';
        renderizarCapa();
        atualizarPreviewGoogle();
    }

    preencherCamposDoArtigo();

    /**
     * Cada ferramenta vem de um <script> separado (ver editor.php) — se
     * algum CDN falhar (rede instável, etc), o resto do editor continua
     * funcionando sem aquela ferramenta específica em vez de travar a
     * página inteira.
     */
    function seDisponivel(nome, Classe) {
        if (typeof Classe === 'undefined') {
            console.warn('Ferramenta "' + nome + '" do Editor.js não carregou — seguindo sem ela.');
            return null;
        }
        return Classe;
    }

    var ferramentas = { imagemZamtech: FerramentaImagem };

    var HeaderTool = seDisponivel('header', window.Header);
    if (HeaderTool) {
        ferramentas.header = { class: HeaderTool, inlineToolbar: true, config: { levels: [2, 3, 4], defaultLevel: 2, placeholder: 'Título' } };
    }

    var ListTool = seDisponivel('list', window.List || window.EditorjsList);
    if (ListTool) {
        ferramentas.list = { class: ListTool, inlineToolbar: true };
    }

    var QuoteTool = seDisponivel('quote', window.Quote);
    if (QuoteTool) {
        ferramentas.quote = { class: QuoteTool, inlineToolbar: true, config: { quotePlaceholder: 'Citação', captionPlaceholder: 'Legenda (opcional)' } };
    }

    var DelimiterTool = seDisponivel('delimiter', window.Delimiter);
    if (DelimiterTool) {
        ferramentas.delimiter = DelimiterTool;
    }

    var EmbedTool = seDisponivel('embed', window.Embed);
    if (EmbedTool) {
        ferramentas.embed = {
            class: EmbedTool,
            config: { services: { youtube: true, vimeo: true, codepen: true, instagram: true, twitter: true } },
        };
    }

    if (typeof window.EditorJS === 'undefined') {
        document.getElementById('editorjs').innerHTML =
            '<p class="dica-upload">Não deu pra carregar o editor de texto (o script do Editor.js não chegou — provavelmente a internet caiu na hora). Recarregue a página.</p>';
        return;
    }

    var editor = new window.EditorJS({
        holder: 'editorjs',
        placeholder: 'Escreva o conteúdo do artigo...',
        tools: ferramentas,
        data: { blocks: window.ARTIGO_INICIAL.blocks || [] },
        minHeight: 200,
    });

    // ---------------------------------------------------------------
    // Salvamento
    // ---------------------------------------------------------------

    function salvar(status, callback) {
        var titulo = campoTitulo.value.trim();
        if (!titulo) {
            alert('Escreva um título pro artigo antes de salvar.');
            campoTitulo.focus();
            return;
        }

        statusSalvamento.textContent = 'Salvando...';

        editor.save()
            .then(function (outputData) {
                return fetch('/blog/admin/ajax/salvar-artigo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify({
                        id: estado.artigoId,
                        titulo: titulo,
                        blocks: outputData.blocks,
                        status: status,
                        categoria: campoCategoria.value,
                        imagem_capa: estado.imagemCapa,
                        imagem_capa_alt: estado.imagemCapaAlt,
                        resumo: campoResumo.value.trim(),
                        meta_titulo: campoMetaTitulo.value.trim(),
                        meta_descricao: campoMetaDescricao.value.trim(),
                        csrf: window.CSRF_TOKEN,
                    }),
                });
            })
            .then(function (r) { return r.json(); })
            .then(function (dados) {
                if (dados.sucesso) {
                    estado.artigoId = dados.id;
                    estado.statusAtual = dados.status;
                    statusSalvamento.textContent = 'Salvo às ' + new Date().toLocaleTimeString('pt-BR').slice(0, 5);
                    if (callback) callback(dados);
                } else {
                    statusSalvamento.textContent = '';
                    alert(dados.mensagem || 'Não deu pra salvar.');
                }
            })
            .catch(function () {
                statusSalvamento.textContent = '';
                alert('Erro de conexão ao salvar.');
            });
    }

    document.getElementById('btn-salvar-rascunho').addEventListener('click', function () {
        salvar('rascunho');
    });

    document.getElementById('btn-publicar').addEventListener('click', function () {
        salvar('publicado', function (dados) {
            if (dados.url) {
                if (confirm('Artigo publicado! Quer abrir a página agora?')) {
                    window.open(dados.url, '_blank');
                }
            }
        });
    });

    // salva automaticamente a cada 30s (mantendo o status atual — rascunho
    // continua rascunho, publicado continua publicado), se já existir um id.
    // Evita perder texto se a aba fechar sem querer.
    setInterval(function () {
        if (estado.artigoId) {
            salvar(estado.statusAtual);
        }
    }, 30000);
})();
