export const prerender = false;
export async function POST({ request }) {
  try {
    const body = await request.json();
    const { cpf_cnpj } = body;

    if (!cpf_cnpj) {
      return new Response(JSON.stringify({ sucesso: false, mensagem: "Informe o CPF ou CNPJ." }), {
        status: 400,
        headers: { "Content-Type": "application/json" }
      });
    }

    const SGP_URL = import.meta.env.SGP_URL;
    const SGP_APP = import.meta.env.SGP_APP;
    const SGP_TOKEN = import.meta.env.SGP_TOKEN;

    const cpfLimpo = cpf_cnpj.replace(/\D/g, "");

    // Faz a requisição para a SGP
    const responseCliente = await fetch(`${SGP_URL}/api/ura/clientes/`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        app: SGP_APP,
        token: SGP_TOKEN,
        cpfcnpj: cpfLimpo
      })
    });

    const rawSgpText = await responseCliente.text();
    
    let dataCliente;
    try {
      dataCliente = JSON.parse(rawSgpText);
    } catch (e) {
      return new Response(JSON.stringify({ 
        sucesso: false, 
        mensagem: `A SGP recusou a conexão ou retornou um erro. Verifique se o seu SGP_TOKEN e SGP_URL estão corretos no arquivo .env.` 
      }), {
        status: 200,
        headers: { "Content-Type": "application/json" }
      });
    }

    if (!dataCliente.clientes || dataCliente.clientes.length === 0) {
      return new Response(JSON.stringify({ sucesso: false, mensagem: "Nenhum cadastro encontrado para este CPF/CNPJ." }), {
        status: 200,
        headers: { "Content-Type": "application/json" }
      });
    }

    let todasFaturas = [];

    dataCliente.clientes.forEach(cliente => {
      if (cliente.titulos && cliente.titulos.length > 0) {
        cliente.titulos.forEach(t => {
          if (t.status === "aberto" || t.status === "abertos" || !t.dataPagamento) {
            todasFaturas.push({
              periodo: t.diasAtraso > 0 ? "Em Atraso" : "Atual",
              vencimento: t.dataVencimento,
              valor: t.valorCorrigido || t.valor,
              linha_digitavel: t.codigoBarras || t.linhaDigitavel || "",
              pix_copia_cola: t.codigoPix || "",
              link_pdf: t.link || t.link_cobranca || "#"
            });
          }
        });
      }
    });

    if (todasFaturas.length === 0) {
      return new Response(JSON.stringify({ sucesso: false, mensagem: "Nenhuma fatura em aberto localizada." }), {
        status: 200,
        headers: { "Content-Type": "application/json" }
      });
    }

    return new Response(JSON.stringify({ sucesso: true, faturas: todasFaturas }), {
      status: 200,
      headers: { "Content-Type": "application/json" }
    });

  } catch (error) {
    return new Response(JSON.stringify({ sucesso: false, mensagem: "Erro interno no servidor: " + error.message }), {
      status: 500,
      headers: { "Content-Type": "application/json" }
    });
  }
}