import type { APIRoute } from 'astro';
import { buscarTodosOsArtigos } from '../../lib/wordpress';

export const GET: APIRoute = async () => {
    const artigos = await buscarTodosOsArtigos();

    const indice = artigos.map((artigo) => ({
        titulo: artigo.titulo,
        resumo: artigo.resumo,
        slug: artigo.slug,
        categoria: artigo.categorias[0]?.nome ?? '',
        imagemCapa: artigo.imagemCapa,
        imagemCapaAlt: artigo.imagemCapaAlt,
        dataPublicacao: artigo.dataPublicacao,
    }));

    return new Response(JSON.stringify(indice), {
        headers: { 'Content-Type': 'application/json' },
    });
};
