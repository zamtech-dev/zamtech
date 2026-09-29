// Gera um arquivo estático /blog/busca.json na hora do build, com um resumo
// de todos os artigos. É esse arquivo que a caixa de pesquisa do blog
// carrega no navegador de quem visita, pra poder filtrar sem precisar
// perguntar nada pro WordPress em tempo real (o site é 100% estático).
import type { APIRoute } from 'astro';
import { buscarTodosOsArtigos } from '../../lib/wordpress';

export const GET: APIRoute = async () => {
    const artigos = await buscarTodosOsArtigos();

    const indice = artigos.map((artigo) => ({
        titulo: artigo.titulo,
        resumo: artigo.resumo,
        slug: artigo.slug,
        categoria: artigo.categorias[0]?.nome ?? '',
    }));

    return new Response(JSON.stringify(indice), {
        headers: { 'Content-Type': 'application/json' },
    });
};
