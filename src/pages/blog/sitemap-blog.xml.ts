import type { APIRoute } from 'astro';
import { buscarTodosOsArtigos } from '../../lib/wordpress';

function formatarData(data: string): string {
    const convertida = new Date(data);
    if (Number.isNaN(convertida.getTime())) {
        return new Date().toISOString();
    }
    return convertida.toISOString();
}

export const GET: APIRoute = async () => {
    const artigos = await buscarTodosOsArtigos();

    const urlsArtigos = artigos
        .map(
            (artigo) => `
    <url>
        <loc>https://zamtech.com.br/blog/${artigo.slug}/</loc>
        <lastmod>${formatarData(artigo.dataModificacao || artigo.dataPublicacao)}</lastmod>
    </url>`
        )
        .join('');

    const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc>https://zamtech.com.br/blog/</loc>
    </url>${urlsArtigos}
</urlset>`;

    return new Response(xml, {
        headers: { 'Content-Type': 'application/xml; charset=utf-8' },
    });
};
