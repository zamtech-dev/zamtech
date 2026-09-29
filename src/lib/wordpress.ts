// Ponte entre o Astro (que gera o site estático) e o WordPress (que só serve
// como "escritório de escrever" — ninguém visita ele diretamente).
//
// Tudo aqui roda em BUILD TIME (quando o GitHub Actions monta o site), nunca
// no navegador de quem visita o blog. Por isso qualquer falha de rede é
// tratada com try/catch e cai num valor vazio, em vez de derrubar o build
// inteiro do site — sem isso, o WordPress fora do ar (ou ainda não instalado)
// quebraria a publicação do site TODO, não só do blog.

// Pasta, não subdomínio: um subdomínio (cms.zamtech.com.br) precisa de um
// registro de DNS novo, criado por quem cuida do DNS do domínio. Uma pasta
// dentro do domínio principal (zamtech.com.br/cms) não precisa de nada
// disso — o domínio já é reconhecido, é só mais um caminho dentro dele.
//
// Exportado (não é mais só "const" interno) porque o formulário de
// comentário e o botão de curtir rodam no NAVEGADOR de quem visita o site
// (não em build time) e precisam saber pra qual endereço mandar o pedido.
export const WORDPRESS_API_URL = 'https://zamtech.com.br/cms/wp-json/wp/v2';

export interface CategoriaWP {
    id: number;
    nome: string;
    slug: string;
    totalArtigos: number;
}

export interface ArtigoWP {
    id: number;
    slug: string;
    titulo: string;
    resumo: string;
    conteudoHtml: string;
    dataPublicacao: string;
    dataModificacao: string;
    imagemCapa: string;
    imagemCapaAlt: string;
    categorias: { nome: string; slug: string }[];
    metaTitulo: string;
    metaDescricao: string;
    autorNome: string;
    autorAvatar: string;
    curtidas: number;
    comentariosContagem: number;
}

export interface ComentarioWP {
    id: number;
    autorNome: string;
    autorAvatar: string;
    conteudoHtml: string;
    data: string;
}

/** Tira as tags HTML de um texto (pra usar em resumo/meta descrição). */
function tirarTagsHtml(html: string): string {
    return html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
}

/** Corta um texto em N caracteres sem cortar uma palavra no meio. */
function cortarTexto(texto: string, tamanho: number): string {
    if (texto.length <= tamanho) {
        return texto;
    }
    return texto.slice(0, tamanho).replace(/\s+\S*$/, '') + '…';
}

/** Tempo de leitura estimado, em minutos (~200 palavras por minuto). */
export function tempoDeLeitura(html: string): number {
    const palavras = tirarTagsHtml(html).split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.ceil(palavras / 200));
}

/** Converte o JSON cru que o WordPress devolve pro formato que as páginas usam. */
function converterPost(post: any): ArtigoWP {
    const midia = post._embedded?.['wp:featuredmedia']?.[0];
    const termos: any[] = post._embedded?.['wp:term']?.[0] ?? [];
    const autor = post._embedded?.author?.[0];
    const titulo = tirarTagsHtml(post.title?.rendered ?? '');
    const resumo = tirarTagsHtml(post.excerpt?.rendered ?? '');
    const conteudoHtml = post.content?.rendered ?? '';

    return {
        id: post.id,
        slug: post.slug,
        titulo,
        resumo: post.acf?.resumo ? tirarTagsHtml(post.acf.resumo) : cortarTexto(resumo, 200),
        conteudoHtml,
        dataPublicacao: post.date,
        dataModificacao: post.modified,
        imagemCapa: midia?.source_url ?? '',
        imagemCapaAlt: midia?.alt_text ?? '',
        categorias: termos.map((t) => ({ nome: t.name, slug: t.slug })),
        metaTitulo: cortarTexto(post.acf?.meta_titulo || titulo, 70),
        metaDescricao: cortarTexto(post.acf?.meta_descricao || resumo, 160),
        // Autor: já vem de graça em todo post do WordPress (é quem estava
        // logado quando escreveu). Não precisa configurar nada a mais no
        // wp-admin — só pedir pro _embed trazer junto, que é o que a busca
        // abaixo já faz.
        autorNome: autor?.name ?? 'Zamtech',
        autorAvatar: autor?.avatar_urls?.['96'] ?? '',
        // Esses dois campos vêm de um mu-plugin extra (wp-mu-plugin-recursos-blog.php)
        // que expõe contagem de curtidas e de comentários na API. Se o
        // plugin ainda não tiver sido instalado, o WordPress simplesmente
        // não manda esses campos — por isso o "?? 0".
        curtidas: Number(post.meta?.zamtech_curtidas ?? 0),
        comentariosContagem: Number(post.zamtech_total_comentarios ?? 0),
    };
}

/**
 * Busca todos os artigos publicados, passando pelas páginas da API do
 * WordPress até acabar. Limitado a 20 páginas (até 2000 artigos) como
 * proteção contra um loop infinito se a API responder algo inesperado.
 */
export async function buscarTodosOsArtigos(): Promise<ArtigoWP[]> {
    const artigos: ArtigoWP[] = [];
    const porPagina = 100;

    try {
        for (let pagina = 1; pagina <= 20; pagina++) {
            const resposta = await fetch(
                `${WORDPRESS_API_URL}/posts?per_page=${porPagina}&page=${pagina}&_embed=true`
            );

            if (!resposta.ok) {
                // página 1 com erro = WordPress fora do ar ou ainda não
                // instalado; página 2+ com erro (404) = simplesmente acabou.
                break;
            }

            const posts = await resposta.json();
            if (!Array.isArray(posts) || posts.length === 0) {
                break;
            }

            artigos.push(...posts.map(converterPost));

            const totalPaginas = Number(resposta.headers.get('X-WP-TotalPages') ?? '1');
            if (pagina >= totalPaginas) {
                break;
            }
        }
    } catch (erro) {
        console.warn('[blog] não deu pra buscar os artigos do WordPress agora:', erro);
    }

    return artigos;
}

/** Busca um artigo pelo slug. Devolve null se não existir (ou WP fora do ar). */
export async function buscarArtigoPorSlug(slug: string): Promise<ArtigoWP | null> {
    try {
        const resposta = await fetch(
            `${WORDPRESS_API_URL}/posts?slug=${encodeURIComponent(slug)}&_embed=true`
        );
        if (!resposta.ok) {
            return null;
        }
        const posts = await resposta.json();
        if (!Array.isArray(posts) || posts.length === 0) {
            return null;
        }
        return converterPost(posts[0]);
    } catch (erro) {
        console.warn(`[blog] não deu pra buscar o artigo "${slug}":`, erro);
        return null;
    }
}

/** Busca as categorias que têm pelo menos um artigo publicado. */
export async function buscarCategorias(): Promise<CategoriaWP[]> {
    try {
        const resposta = await fetch(`${WORDPRESS_API_URL}/categories?per_page=100&hide_empty=true`);
        if (!resposta.ok) {
            return [];
        }
        const categorias = await resposta.json();
        if (!Array.isArray(categorias)) {
            return [];
        }
        return categorias
            .filter((c: any) => c.slug !== 'sem-categoria' && c.count > 0)
            .map((c: any) => ({ id: c.id, nome: c.name, slug: c.slug, totalArtigos: c.count }));
    } catch (erro) {
        console.warn('[blog] não deu pra buscar as categorias do WordPress agora:', erro);
        return [];
    }
}

/**
 * Busca os comentários já aprovados de um artigo (pra mostrar no build). Os
 * comentários novos que alguém mandar pelo formulário do site não passam
 * por aqui — vão direto do navegador da pessoa pro WordPress (ver o script
 * em [slug].astro), e só aparecem aqui na próxima vez que o site for
 * reconstruído (depois de você aprovar, se a aprovação manual estiver
 * ligada).
 */
export async function buscarComentarios(postId: number): Promise<ComentarioWP[]> {
    try {
        const resposta = await fetch(
            `${WORDPRESS_API_URL}/comments?post=${postId}&order=asc&per_page=100&_fields=id,author_name,author_avatar_urls,content,date`
        );
        if (!resposta.ok) {
            return [];
        }
        const comentarios = await resposta.json();
        if (!Array.isArray(comentarios)) {
            return [];
        }
        return comentarios.map((c: any) => ({
            id: c.id,
            autorNome: c.author_name || 'Anônimo',
            autorAvatar: c.author_avatar_urls?.['48'] ?? '',
            conteudoHtml: c.content?.rendered ?? '',
            data: c.date,
        }));
    } catch (erro) {
        console.warn(`[blog] não deu pra buscar os comentários do artigo ${postId}:`, erro);
        return [];
    }
}

/** Ícone (dos assets do próprio site) pra identificar cada categoria visualmente. */
export function iconeCategoria(nomeCategoria: string): string {
    const mapa: Record<string, string> = {
        Residencial: '/assets/icons/others-icons/casa-house.svg',
        Empresarial: '/assets/icons/others-icons/empresarial.svg',
        Dicas: '/assets/icons/tech-icon.svg',
        Novidades: '/assets/icons/star.svg',
    };
    return mapa[nomeCategoria] ?? '/assets/icons/others-icons/casa-house.svg';
}

export function formatarDataBr(dataIso: string): string {
    if (!dataIso) {
        return '';
    }
    const meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    const data = new Date(dataIso);
    return `${data.getDate()} de ${meses[data.getMonth()]} de ${data.getFullYear()}`;
}

export function formatarDataLeitura(dataIso: string): string {
    if (!dataIso) {
        return '';
    }
    const meses = [
        'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];
    const data = new Date(dataIso);
    return `${data.getDate()} de ${meses[data.getMonth()]} de ${data.getFullYear()}`;
}

export const POR_PAGINA = 9;
