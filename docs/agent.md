# Recall — guia para o agente

Memória episódica: **o que aconteceu, quando aconteceu, e a quem ou ao que está ligado.**
Não é um bloco de notas. Serve para responder mais tarde a perguntas com eixo temporal
("o que aconteceu nesta altura em anos anteriores?", "o que comprei no ano passado?",
"o que me lembro sobre esta pessoa?").

App id: `recall`. Todas as operações abaixo são rotas **OCS**, chamáveis com `app_api_call`.

---

## A regra que se erra primeiro

**`occurred_at` é a data em que a coisa ACONTECEU, nunca a data de hoje.**

Se o utilizador disser "no verão passado fomos ao Gerês", o `occurred_at` é do verão
passado. A data de registo é guardada à parte, automaticamente. Se não souberes a data
exata, usa `occurred_precision`:

- `day` — sabes o dia (por omissão)
- `month` — só sabes o mês ("em março")
- `year` — só sabes o ano ("em 2019")

Nunca inventes um dia para preencher o campo. Se o utilizador não disse quando, **pergunta**.

---

## Que operação usar

| A pergunta é… | Usa |
|---|---|
| "o que aconteceu nesta altura em anos anteriores?" | `GET /episodes/on-this-day` |
| "o que aconteceu em <período>?" | `GET /episodes?from=&to=` |
| "o que me lembro sobre <pessoa>?" | `GET /episodes?link_kind=contact&link_ref=<uid>` |
| procurar por palavras | `GET /episodes?q=` |
| guardar algo que aconteceu | `POST /episodes` |

O `on-this-day` devolve **agrupado por ano**, que é como a pergunta é feita
("no ano passado…", "há três anos…"). Não o reproduzas como lista corrida.

---

## Operações

```
GET    /ocs/v2.php/apps/recall/api/v1/episodes
         ?from=YYYY-MM-DD &to=YYYY-MM-DD &type= &q= &limit= &offset=
         ?link_kind=contact|photo|file|folder|event|task &link_ref=<id>

GET    /ocs/v2.php/apps/recall/api/v1/episodes/on-this-day
         ?date=YYYY-MM-DD   (por omissão: hoje)
         &window=<dias à volta da data, por omissão 0>
         &years_back=<quantos anos para trás, por omissão 5>

POST   /ocs/v2.php/apps/recall/api/v1/episodes
GET    /ocs/v2.php/apps/recall/api/v1/episodes/{id}
PUT    /ocs/v2.php/apps/recall/api/v1/episodes/{id}
DELETE /ocs/v2.php/apps/recall/api/v1/episodes/{id}

GET    /ocs/v2.php/apps/recall/api/v1/contacts?q=<nome>
GET    /ocs/v2.php/apps/recall/api/v1/files/resolve?link=<url ou file id>
```

### Criar um episódio

```json
{
  "title": "Prenda de anos da Sofia",
  "occurred_at": "2024-09-26",
  "occurred_precision": "day",
  "type": "compra",
  "body": "Livro de aguarelas",
  "source": "agent",
  "meta": { "valor": 24.90 },
  "links": [ { "kind": "contact", "ref": "<uid>", "label": "Sofia" } ]
}
```

Obrigatórios: `title` e `occurred_at`. Põe sempre `"source": "agent"` no que criares —
é o que distingue o que deduziste do que o utilizador escreveu à mão.

`type` é livre; usa os que já existem quando servirem: `compra`, `viagem`, `saude`,
`ideia`, `prenda`.

---

## Ligações

`kind` tem de ser um de: `contact`, `photo`, `file`, `folder`, `event`, `task`.

O `ref` é **sempre um identificador estável**, nunca um nome nem um caminho:

- `contact` → UID do CardDAV. Não o sabes de cor: descobre-o com
  `GET /api/v1/contacts?q=<nome>`, que devolve `{uid, name}`.
- `photo` / `file` / `folder` → file id do Nextcloud. Se tiveres um URL,
  passa-o por `GET /api/v1/files/resolve?link=<url>`, que devolve já o `kind` certo.
- `event` / `task` → UID do CalDAV do evento ou da tarefa.

O `label` é o nome legível **no momento em que ligas**. Grava-o sempre: a foto pode ser
apagada e o contacto pode desaparecer, e a memória tem de continuar a ler-se sem eles.

---

## Quando NÃO guardar

O valor do `on-this-day` vem de as memórias serem poucas e escolhidas. Uma memória por
dia enche isto de trivialidades e destrói a utilidade da consulta — e ninguém volta atrás
para limpar.

- Não guardes por tua iniciativa o que é rotina ou ruído de sistema.
- Se achares que algo merece ser recordado, **pergunta ao utilizador** antes de gravar.
- Não gravar é sempre recuperável; gravar lixo, na prática, não é.
