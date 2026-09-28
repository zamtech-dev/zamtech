(function () {
    'use strict';

    var container = document.getElementById('blocos-container');
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
    // Criação de blocos
    // ---------------------------------------------------------------

    function criarControlesBloco(blocoEl) {
        var controles = document.createElement('div');
        controles.className = 'bloco-controles';

        var adicionar = document.createElement('button');
        adicionar.type = 'button';
        adicionar.title = 'Adicionar bloco depois deste';
        adicionar.textContent = '+';
        adicionar.addEventListener('click', function () {
            var rect = adicionar.getBoundingClientRect();
            abrirMenuAdd(rect.right + 6, rect.top, blocoEl);
        });
        controles.appendChild(adicionar);

        var subir = document.createElement('button');
        subir.type = 'button';
        subir.title = 'Mover pra cima';
        subir.textContent = '↑';
        subir.addEventListener('click', function () {
            var anterior = blocoEl.previousElementSibling;
            if (anterior) {
                container.insertBefore(blocoEl, anterior);
            }
        });

        var descer = document.createElement('button');
        descer.type = 'button';
        descer.title = 'Mover pra baixo';
        descer.textContent = '↓';
        descer.addEventListener('click', function () {
            var proximo = blocoEl.nextElementSibling;
            if (proximo) {
                container.insertBefore(proximo, blocoEl);
            }
        });

        var excluir = document.createElement('button');
        excluir.type = 'button';
        excluir.title = 'Excluir bloco';
        excluir.className = 'btn-excluir-bloco';
        excluir.textContent = '✕';
        excluir.addEventListener('click', function () {
            blocoEl.remove();
        });

        controles.appendChild(subir);
        controles.appendChild(descer);
        controles.appendChild(excluir);
        blocoEl.appendChild(controles);
    }

    function criarBlocoBase(tipo) {
        var el = document.createElement('div');
        el.className = 'bloco';
        el.setAttribute('data-type', tipo);
        criarControlesBloco(el);
        return el;
    }

    function criarBlocoTexto(tipo, htmlInicial, nivel) {
        var el = criarBlocoBase(tipo);
        if (nivel) {
            el.setAttribute('data-nivel', nivel);
        }

        var editavel = document.createElement('div');
        editavel.className = 'bloco-conteudo-editavel';
        editavel.contentEditable = 'true';
        editavel.innerHTML = htmlInicial || '';
        el.appendChild(editavel);

        if (tipo === 'quote') {
            var legenda = document.createElement('input');
            legenda.type = 'text';
            legenda.className = 'bloco-legenda-input';
            legenda.placeholder = 'Legenda da citação (opcional)';
            legenda.setAttribute('data-legenda', '1');
            el.appendChild(legenda);
        }

        ativarBarraFormatacao(editavel);
        return el;
    }

    function criarBlocoDelimitador() {
        var el = criarBlocoBase('delimiter');
        var linha = document.createElement('div');
        linha.className = 'bloco-conteudo-editavel';
        el.appendChild(linha);
        return el;
    }

    function criarBlocoImagem(src, alt, legenda) {
        var el = criarBlocoBase('image');
        el.setAttribute('data-src', src);
        el.setAttribute('data-alt', alt || '');

        var img = document.createElement('img');
        img.src = src;
        img.alt = alt || '';
        el.appendChild(img);

        var legendaInput = document.createElement('input');
        legendaInput.type = 'text';
        legendaInput.className = 'bloco-legenda-input';
        legendaInput.placeholder = 'Legenda (opcional)';
        legendaInput.value = legenda || '';
        legendaInput.setAttribute('data-legenda', '1');
        el.appendChild(legendaInput);

        return el;
    }

    function criarBlocoEmbed(provider, url) {
        var el = criarBlocoBase('embed');
        el.setAttribute('data-provider', provider);
        el.setAttribute('data-url', url);

        var caixa = document.createElement('div');
        caixa.className = 'embed-caixa';
        caixa.innerHTML = gerarPreviewEmbed(provider, url) || ('Incorporação de ' + provider + ': ' + escaparHtml(url));
        el.appendChild(caixa);

        carregarScriptsEmbedSeNecessario(provider);
        return el;
    }

    function inserirBlocoDepois(referencia, blocoEl) {
        if (referencia && referencia.nextElementSibling) {
            container.insertBefore(blocoEl, referencia.nextElementSibling);
        } else if (referencia) {
            container.appendChild(blocoEl);
        } else {
            container.appendChild(blocoEl);
        }
    }

    // ---------------------------------------------------------------
    // Menu "+"
    // ---------------------------------------------------------------

    var menuAddBloco = document.getElementById('menu-add-bloco');
    var blocoReferenciaAtual = null;

    function abrirMenuAdd(x, y, referencia) {
        blocoReferenciaAtual = referencia;
        menuAddBloco.hidden = false;

        // mede o menu já visível e garante que ele não fique cortado fora
        // da tela (isso acontecia perto do fim da página ou da borda direita)
        var menuRect = menuAddBloco.getBoundingClientRect();
        var maxLeft = Math.max(8, window.innerWidth - menuRect.width - 8);
        var maxTop = Math.max(8, window.innerHeight - menuRect.height - 8);
        x = Math.min(Math.max(8, x), maxLeft);
        y = Math.min(Math.max(8, y), maxTop);

        menuAddBloco.style.left = x + 'px';
        menuAddBloco.style.top = y + 'px';
    }

    function fecharMenuAdd() {
        menuAddBloco.hidden = true;
        blocoReferenciaAtual = undefined;
    }

    document.addEventListener('click', function (ev) {
        if (!menuAddBloco.hidden && !menuAddBloco.contains(ev.target) && ev.target.id !== 'btn-add-final') {
            fecharMenuAdd();
        }
    });

    menuAddBloco.querySelectorAll('button').forEach(function (botao) {
        botao.addEventListener('click', function () {
            var tipo = botao.getAttribute('data-tipo');
            var referencia = blocoReferenciaAtual;
            fecharMenuAdd();

            if (tipo === 'paragraph') {
                var novo = criarBlocoTexto('paragraph', '');
                inserirBlocoDepois(referencia, novo);
                novo.querySelector('.bloco-conteudo-editavel').focus();
            } else if (tipo === 'heading') {
                var nivel = botao.getAttribute('data-nivel');
                var novoH = criarBlocoTexto('heading', '', nivel);
                inserirBlocoDepois(referencia, novoH);
                novoH.querySelector('.bloco-conteudo-editavel').focus();
            } else if (tipo === 'quote') {
                var novoQ = criarBlocoTexto('quote', '');
                inserirBlocoDepois(referencia, novoQ);
                novoQ.querySelector('.bloco-conteudo-editavel').focus();
            } else if (tipo === 'delimiter') {
                inserirBlocoDepois(referencia, criarBlocoDelimitador());
            } else if (tipo === 'image') {
                abrirModalImagem(function (dados) {
                    inserirBlocoDepois(referencia, criarBlocoImagem(dados.url, dados.alt, dados.legenda));
                });
            } else if (tipo === 'embed') {
                abrirModalEmbed(function (dados) {
                    inserirBlocoDepois(referencia, criarBlocoEmbed(dados.provider, dados.url));
                });
            }
        });
    });

    document.getElementById('btn-add-final').addEventListener('click', function (ev) {
        var rect = ev.target.getBoundingClientRect();
        abrirMenuAdd(rect.left, rect.bottom + 6, container.lastElementChild);
    });

    // adiciona um pequeno "+" quando o mouse passa entre blocos seria ideal,
    // mas pra manter simples: clique com botão direito no bloco (ou o "+"
    // do rodapé) já cobre o fluxo principal. Também oferecemos um atalho:
    // Enter no fim de um parágrafo cria um novo parágrafo em seguida.
    container.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' || ev.shiftKey) {
            return;
        }
        var alvo = ev.target;
        if (!alvo.classList || !alvo.classList.contains('bloco-conteudo-editavel')) {
            return;
        }

        // dentro de uma lista (bullet ou numerada), deixa o Enter criar um
        // item novo normalmente — só cria um bloco novo fora de listas.
        var selecao = window.getSelection();
        if (selecao.anchorNode) {
            var noAtual = selecao.anchorNode.nodeType === 3 ? selecao.anchorNode.parentElement : selecao.anchorNode;
            if (noAtual && noAtual.closest('li')) {
                return;
            }
        }

        var blocoAtual = alvo.closest('.bloco');
        if (blocoAtual.getAttribute('data-type') === 'quote') {
            return; // deixa quebrar linha dentro da citação
        }
        ev.preventDefault();
        var novo = criarBlocoTexto('paragraph', '');
        inserirBlocoDepois(blocoAtual, novo);
        novo.querySelector('.bloco-conteudo-editavel').focus();
    });

    // ---------------------------------------------------------------
    // Barra de formatação (negrito, itálico, link)
    // ---------------------------------------------------------------

    var barraFormatacao = document.getElementById('barra-formatacao');

    function ativarBarraFormatacao(editavel) {
        editavel.addEventListener('mouseup', mostrarBarraSeTiverSelecao);
        editavel.addEventListener('keyup', mostrarBarraSeTiverSelecao);
        editavel.addEventListener('paste', colarComoTextoSimples);
    }

    /**
     * Cola só o texto puro, sem a formatação de origem (fonte, cor, caixa
     * branca de fundo etc. que vem colada de Word/Google Docs/sites). Evita
     * o artigo herdar um visual estranho de fora do site.
     */
    function colarComoTextoSimples(ev) {
        ev.preventDefault();
        var texto = (ev.clipboardData || window.clipboardData).getData('text/plain');
        document.execCommand('insertText', false, texto);
    }

    function mostrarBarraSeTiverSelecao() {
        var selecao = window.getSelection();
        if (!selecao || selecao.isCollapsed || selecao.rangeCount === 0) {
            barraFormatacao.hidden = true;
            return;
        }
        var range = selecao.getRangeAt(0);
        var rect = range.getBoundingClientRect();
        if (rect.width === 0 && rect.height === 0) {
            barraFormatacao.hidden = true;
            return;
        }
        barraFormatacao.style.left = (rect.left + rect.width / 2 - 70) + 'px';
        barraFormatacao.style.top = (rect.top - 44 + window.scrollY) + 'px';
        barraFormatacao.hidden = false;
    }

    document.addEventListener('mousedown', function (ev) {
        if (!barraFormatacao.contains(ev.target)) {
            barraFormatacao.hidden = true;
        }
    });

    barraFormatacao.querySelectorAll('button').forEach(function (botao) {
        botao.addEventListener('mousedown', function (ev) {
            ev.preventDefault(); // não perde a seleção de texto
        });
        botao.addEventListener('click', function () {
            var comando = botao.getAttribute('data-cmd');
            if (comando === 'link') {
                var url = prompt('Cole o link (comece com https://)');
                if (!url) {
                    return;
                }
                url = url.trim();
                if (!/^(https?:\/\/|mailto:|tel:|\/)/i.test(url)) {
                    alert('Link inválido. Use um endereço começando com https://');
                    return;
                }
                document.execCommand('createLink', false, url);
            } else {
                document.execCommand(comando, false, null);
            }
        });
    });

    // ---------------------------------------------------------------
    // Modal de imagem (upload com corte/zoom, ou banco de imagens)
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
    var arquivoSelecionadoBase64 = null;
    var cropEstado = { escala: 1, x: 0, y: 0, arrastando: false, inicioX: 0, inicioY: 0 };
    var urlBancoSelecionada = null;
    var imagemExternaSelecionada = null; // { completa, download_location, credito } — Unsplash/Pexels

    function resetarModalImagem() {
        areaSelecionarArquivo.hidden = false;
        areaCortarImagem.hidden = true;
        inputArquivo.value = '';
        campoAltImagem.value = '';
        campoLegendaImagem.value = '';
        arquivoSelecionadoBase64 = null;
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
                        arquivoSelecionadoBase64 = null;
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
                        arquivoSelecionadoBase64 = null;
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
        // zoom a partir do CENTRO do viewport, não do canto superior
        // esquerdo. A matemática: o ponto da imagem que está hoje embaixo
        // do centro do viewport tem que continuar embaixo do centro depois
        // de mudar a escala — por isso recalculamos x/y junto com a escala.
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
            // espera o navegador recalcular o tamanho do viewport com a
            // nova proporção antes de reposicionar/centralizar a imagem
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
    // toque (celular/tablet)
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
    // Modal de incorporar (embed)
    // ---------------------------------------------------------------

    var modalEmbed = document.getElementById('modal-embed');
    var campoUrlEmbed = document.getElementById('campo-url-embed');
    var previewEmbedEl = document.getElementById('preview-embed');
    var erroEmbedEl = document.getElementById('erro-embed');
    var callbackEmbedAtual = null;
    var embedDetectado = null;

    function detectarProvider(url) {
        if (/youtu\.?be/.test(url)) return 'youtube';
        if (/vimeo\.com/.test(url)) return 'vimeo';
        if (/codepen\.io/.test(url)) return 'codepen';
        if (/instagram\.com/.test(url)) return 'instagram';
        if (/(twitter|x)\.com/.test(url)) return 'twitter';
        return null;
    }

    function gerarPreviewEmbed(provider, url) {
        if (provider === 'youtube') {
            var m = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|shorts\/))([A-Za-z0-9_-]{6,})/);
            if (!m) return null;
            return '<iframe src="https://www.youtube-nocookie.com/embed/' + m[1] + '" allowfullscreen loading="lazy"></iframe>';
        }
        if (provider === 'vimeo') {
            var mv = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
            if (!mv) return null;
            return '<iframe src="https://player.vimeo.com/video/' + mv[1] + '" allowfullscreen loading="lazy"></iframe>';
        }
        if (provider === 'codepen') {
            var mc = url.match(/codepen\.io\/([^\/]+)\/(?:pen|details|full)\/([A-Za-z0-9]+)/);
            if (!mc) return null;
            return '<iframe src="https://codepen.io/' + mc[1] + '/embed/' + mc[2] + '?default-tab=result" loading="lazy"></iframe>';
        }
        if (provider === 'instagram') {
            if (!/instagram\.com\/(p|reel|tv)\//.test(url)) return null;
            return '<blockquote class="instagram-media" data-instgrm-permalink="' + escaparHtml(url) + '"></blockquote>';
        }
        if (provider === 'twitter') {
            if (!/\/status\/\d+/.test(url)) return null;
            return '<blockquote class="twitter-tweet"><a href="' + escaparHtml(url) + '"></a></blockquote>';
        }
        return null;
    }

    function carregarScriptsEmbedSeNecessario(provider) {
        if (provider === 'instagram' && !window.__instgrmCarregado) {
            window.__instgrmCarregado = true;
            var s = document.createElement('script');
            s.async = true;
            s.src = 'https://www.instagram.com/embed.js';
            document.body.appendChild(s);
        }
        if (provider === 'twitter' && !window.__twttrCarregado) {
            window.__twttrCarregado = true;
            var s2 = document.createElement('script');
            s2.async = true;
            s2.src = 'https://platform.twitter.com/widgets.js';
            document.body.appendChild(s2);
        }
        // reprocessa embeds sociais já existentes na página quando o script carrega
        setTimeout(function () {
            if (window.instgrm) { window.instgrm.Embeds.process(); }
            if (window.twttr && window.twttr.widgets) { window.twttr.widgets.load(); }
        }, 800);
    }

    function abrirModalEmbed(callback) {
        callbackEmbedAtual = callback;
        campoUrlEmbed.value = '';
        previewEmbedEl.innerHTML = '';
        erroEmbedEl.hidden = true;
        embedDetectado = null;
        modalEmbed.hidden = false;
    }

    campoUrlEmbed.addEventListener('input', function () {
        var url = campoUrlEmbed.value.trim();
        erroEmbedEl.hidden = true;
        if (!url) {
            previewEmbedEl.innerHTML = '';
            embedDetectado = null;
            return;
        }
        var provider = detectarProvider(url);
        if (!provider) {
            previewEmbedEl.innerHTML = '';
            embedDetectado = null;
            return;
        }
        var preview = gerarPreviewEmbed(provider, url);
        if (preview) {
            previewEmbedEl.innerHTML = preview;
            embedDetectado = { provider: provider, url: url };
            carregarScriptsEmbedSeNecessario(provider);
        } else {
            previewEmbedEl.innerHTML = '';
            embedDetectado = null;
        }
    });

    document.getElementById('btn-confirmar-embed').addEventListener('click', function () {
        if (!embedDetectado) {
            erroEmbedEl.textContent = 'Cole um link válido de YouTube, Instagram, X, Vimeo ou CodePen.';
            erroEmbedEl.hidden = false;
            return;
        }
        callbackEmbedAtual(embedDetectado);
        fecharTodosOsModais();
    });

    // ---------------------------------------------------------------
    // Fechar modais
    // ---------------------------------------------------------------

    function fecharTodosOsModais() {
        modalImagem.hidden = true;
        modalEmbed.hidden = true;
    }

    document.querySelectorAll('[data-fechar-modal]').forEach(function (botao) {
        botao.addEventListener('click', fecharTodosOsModais);
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

    // Aproximação só pra pré-visualização — o slug de verdade é gerado no
    // servidor (e pode ganhar um "-2" no fim se já existir um artigo com
    // o mesmo nome), mas isso aqui já dá uma ideia bem próxima.
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
    // Carregar dados iniciais (edição de artigo existente)
    // ---------------------------------------------------------------

    function carregarDadosIniciais() {
        var dados = window.ARTIGO_INICIAL;
        campoTitulo.value = dados.titulo || '';
        ajustarAlturaTitulo();
        campoCategoria.value = dados.categoria || '';
        campoResumo.value = dados.resumo || '';
        campoMetaTitulo.value = dados.meta_titulo || '';
        campoMetaDescricao.value = dados.meta_descricao || '';
        renderizarCapa();
        atualizarPreviewGoogle();

        (dados.blocks || []).forEach(function (bloco) {
            var el = null;
            if (bloco.type === 'paragraph') {
                el = criarBlocoTexto('paragraph', bloco.html || '');
            } else if (bloco.type === 'heading') {
                el = criarBlocoTexto('heading', escaparHtml(bloco.text || ''), bloco.level || 2);
            } else if (bloco.type === 'quote') {
                el = criarBlocoTexto('quote', escaparHtml(bloco.text || ''));
                if (bloco.legenda) {
                    el.querySelector('[data-legenda]').value = bloco.legenda;
                }
            } else if (bloco.type === 'delimiter') {
                el = criarBlocoDelimitador();
            } else if (bloco.type === 'image') {
                el = criarBlocoImagem(bloco.src, bloco.alt, bloco.legenda);
            } else if (bloco.type === 'embed') {
                el = criarBlocoEmbed(bloco.provider, bloco.url);
            }
            if (el) {
                container.appendChild(el);
            }
        });

        if (!container.children.length) {
            container.appendChild(criarBlocoTexto('paragraph', ''));
        }
    }

    // ---------------------------------------------------------------
    // Serialização e salvamento
    // ---------------------------------------------------------------

    function serializarBlocos() {
        var blocos = [];
        Array.prototype.forEach.call(container.children, function (blocoEl) {
            var tipo = blocoEl.getAttribute('data-type');
            if (tipo === 'paragraph') {
                var editavel = blocoEl.querySelector('.bloco-conteudo-editavel');
                var html = editavel.innerHTML.trim();
                if (html && html !== '<br>') {
                    blocos.push({ type: 'paragraph', html: html });
                }
            } else if (tipo === 'heading') {
                var texto = blocoEl.querySelector('.bloco-conteudo-editavel').innerText.trim();
                if (texto) {
                    blocos.push({ type: 'heading', level: parseInt(blocoEl.getAttribute('data-nivel'), 10) || 2, text: texto });
                }
            } else if (tipo === 'quote') {
                var textoQ = blocoEl.querySelector('.bloco-conteudo-editavel').innerText.trim();
                var legendaQ = blocoEl.querySelector('[data-legenda]');
                if (textoQ) {
                    blocos.push({ type: 'quote', text: textoQ, legenda: legendaQ ? legendaQ.value.trim() : '' });
                }
            } else if (tipo === 'delimiter') {
                blocos.push({ type: 'delimiter' });
            } else if (tipo === 'image') {
                var legendaImg = blocoEl.querySelector('[data-legenda]');
                blocos.push({
                    type: 'image',
                    src: blocoEl.getAttribute('data-src'),
                    alt: blocoEl.getAttribute('data-alt') || '',
                    legenda: legendaImg ? legendaImg.value.trim() : '',
                });
            } else if (tipo === 'embed') {
                blocos.push({
                    type: 'embed',
                    provider: blocoEl.getAttribute('data-provider'),
                    url: blocoEl.getAttribute('data-url'),
                });
            }
        });
        return blocos;
    }

    function salvar(status, callback) {
        var titulo = campoTitulo.value.trim();
        if (!titulo) {
            alert('Escreva um título pro artigo antes de salvar.');
            campoTitulo.focus();
            return;
        }

        statusSalvamento.textContent = 'Salvando...';

        fetch('/blog/admin/ajax/salvar-artigo.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({
                id: estado.artigoId,
                titulo: titulo,
                blocks: serializarBlocos(),
                status: status,
                categoria: campoCategoria.value,
                imagem_capa: estado.imagemCapa,
                imagem_capa_alt: estado.imagemCapaAlt,
                resumo: campoResumo.value.trim(),
                meta_titulo: campoMetaTitulo.value.trim(),
                meta_descricao: campoMetaDescricao.value.trim(),
                csrf: window.CSRF_TOKEN,
            }),
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

    carregarDadosIniciais();
})();
