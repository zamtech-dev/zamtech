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

function tirarTagsHtml(html: string): string {
    return html.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim();
}

function cortarTexto(texto: string, tamanho: number): string {
    if (texto.length <= tamanho) {
        return texto;
    }
    return texto.slice(0, tamanho).replace(/\s+\S*$/, '') + '…';
}

export function tempoDeLeitura(html: string): number {
    const palavras = tirarTagsHtml(html).split(/\s+/).filter(Boolean).length;
    return Math.max(1, Math.ceil(palavras / 200));
}

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
        autorNome: autor?.name ?? 'Zamtech',
        autorAvatar: autor?.avatar_urls?.['96'] ?? '',
        curtidas: Number(post.zamtech_curtidas ?? 0),
        comentariosContagem: Number(post.zamtech_total_comentarios ?? 0),
    };
}

export async function buscarTodosOsArtigos(): Promise<ArtigoWP[]> {
    const artigos: ArtigoWP[] = [];
    const porPagina = 100;

    try {
        for (let pagina = 1; pagina <= 20; pagina++) {
            const resposta = await fetch(
                `${WORDPRESS_API_URL}/posts?per_page=${porPagina}&page=${pagina}&_embed=true`
            );

            if (!resposta.ok) {
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
