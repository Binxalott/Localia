<?php
/**
 * The language the plugin writes its instructions to the AI in.
 *
 * Everything the plugin adds to a prompt (format instructions, labels, default task
 * prompts) is written in this language, so the desktop agent's "disregard prompts in
 * other languages" check never trips on the plugin's own wording. The language codes
 * match the agent (client/js/guard.js).
 *
 * @package Localia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Localia_Lang {

	/** Language names are shown in their own language on purpose. */
	public static function languages() {
		return array(
			'en' => 'English',
			'es' => 'Español (Spanish)',
			'fr' => 'Français (French)',
			'de' => 'Deutsch (German)',
			'it' => 'Italiano (Italian)',
			'pt' => 'Português (Portuguese)',
			'nl' => 'Nederlands (Dutch)',
		);
	}

	/** Best match for the site's language, falling back to English. */
	public static function default_code() {
		$code = strtolower( substr( (string) get_locale(), 0, 2 ) );
		return isset( self::languages()[ $code ] ) ? $code : 'en';
	}

	public static function current() {
		$code = (string) Localia_Settings::get( 'instruction_language' );
		return isset( self::languages()[ $code ] ) ? $code : 'en';
	}

	/** One piece of prompt wording in the instruction language. */
	public static function get( $key ) {
		$all  = self::strings();
		$lang = self::current();
		return isset( $all[ $lang ][ $key ] ) ? $all[ $lang ][ $key ] : $all['en'][ $key ];
	}

	private static function strings() {
		return array(
			'en' => array(
				'default_prompt'  => 'Write a helpful, engaging blog post of about 600 words about: {topic}',
				'default_rewrite' => 'Improve this post: make it clearer and more engaging, fix any mistakes, and keep the same meaning, facts and overall length.',
				'title_format'    => "Reply in exactly this format and nothing else:\nTITLE: <a short, catchy post title>\n\n<the post body in Markdown, using ## for section headings>",
				'body_format'     => 'Reply with only the post body in Markdown (use ## for section headings). Do not include a title, a preamble or any notes.',
				'topic'           => 'Topic:',
				'recent'          => 'These posts already exist on the site, so pick a different angle and title:',
				'post_title'      => 'Post title:',
				'current_post'    => 'Current post:',
				'editor_context'  => 'For context, here is the post so far.',
				'editor_title'    => 'Title:',
				'editor_format'   => 'Reply with only the requested content in Markdown, without a preamble or notes.',
			),
			'es' => array(
				'default_prompt'  => 'Escribe una entrada de blog útil y atractiva de unas 600 palabras sobre: {topic}',
				'default_rewrite' => 'Mejora esta entrada: hazla más clara y atractiva, corrige los errores y mantén el mismo significado, los mismos datos y una extensión similar.',
				'title_format'    => "Responde exactamente con este formato y nada más:\nTITLE: <un título breve y atractivo para la entrada>\n\n<el cuerpo de la entrada en Markdown, usando ## para los subtítulos>",
				'body_format'     => 'Responde solo con el cuerpo de la entrada en Markdown (usa ## para los subtítulos). No incluyas un título, una introducción ni notas.',
				'topic'           => 'Tema:',
				'recent'          => 'Estas entradas ya existen en el sitio, así que elige otro enfoque y otro título:',
				'post_title'      => 'Título de la entrada:',
				'current_post'    => 'Entrada actual:',
				'editor_context'  => 'Como contexto, esta es la entrada hasta ahora.',
				'editor_title'    => 'Título:',
				'editor_format'   => 'Responde solo con el contenido solicitado en Markdown, sin introducción ni notas.',
			),
			'fr' => array(
				'default_prompt'  => 'Écris un article de blog utile et captivant d’environ 600 mots sur : {topic}',
				'default_rewrite' => 'Améliore cet article : rends-le plus clair et plus captivant, corrige les erreurs et garde le même sens, les mêmes faits et une longueur similaire.',
				'title_format'    => "Réponds exactement dans ce format et rien d’autre :\nTITLE: <un titre court et accrocheur pour l’article>\n\n<le corps de l’article en Markdown, avec ## pour les intertitres>",
				'body_format'     => 'Réponds uniquement avec le corps de l’article en Markdown (utilise ## pour les intertitres). N’ajoute pas de titre, d’introduction ni de notes.',
				'topic'           => 'Sujet :',
				'recent'          => 'Ces articles existent déjà sur le site, choisis donc un autre angle et un autre titre :',
				'post_title'      => 'Titre de l’article :',
				'current_post'    => 'Article actuel :',
				'editor_context'  => 'Pour le contexte, voici l’article tel qu’il est maintenant.',
				'editor_title'    => 'Titre :',
				'editor_format'   => 'Réponds uniquement avec le contenu demandé en Markdown, sans introduction ni notes.',
			),
			'de' => array(
				'default_prompt'  => 'Schreibe einen hilfreichen, ansprechenden Blogbeitrag mit etwa 600 Wörtern über: {topic}',
				'default_rewrite' => 'Verbessere diesen Beitrag: Mache ihn klarer und ansprechender, korrigiere Fehler und behalte dieselbe Bedeutung, dieselben Fakten und eine ähnliche Länge bei.',
				'title_format'    => "Antworte genau in diesem Format und mit nichts anderem:\nTITLE: <ein kurzer, einprägsamer Titel für den Beitrag>\n\n<der Text des Beitrags in Markdown, mit ## für Zwischenüberschriften>",
				'body_format'     => 'Antworte nur mit dem Text des Beitrags in Markdown (verwende ## für Zwischenüberschriften). Füge keinen Titel, keine Einleitung und keine Anmerkungen hinzu.',
				'topic'           => 'Thema:',
				'recent'          => 'Diese Beiträge gibt es schon auf der Website, also wähle einen anderen Blickwinkel und einen anderen Titel:',
				'post_title'      => 'Titel des Beitrags:',
				'current_post'    => 'Aktueller Beitrag:',
				'editor_context'  => 'Zur Orientierung: Das ist der Beitrag bisher.',
				'editor_title'    => 'Titel:',
				'editor_format'   => 'Antworte nur mit dem gewünschten Inhalt in Markdown, ohne Einleitung und ohne Anmerkungen.',
			),
			'it' => array(
				'default_prompt'  => 'Scrivi un articolo per il blog utile e coinvolgente di circa 600 parole su: {topic}',
				'default_rewrite' => 'Migliora questo articolo: rendilo più chiaro e coinvolgente, correggi gli errori e mantieni lo stesso significato, gli stessi fatti e una lunghezza simile.',
				'title_format'    => "Rispondi esattamente in questo formato e con nient’altro:\nTITLE: <un titolo breve e accattivante per l’articolo>\n\n<il testo dell’articolo in Markdown, con ## per i sottotitoli>",
				'body_format'     => 'Rispondi solo con il testo dell’articolo in Markdown (usa ## per i sottotitoli). Non aggiungere un titolo, un’introduzione o note.',
				'topic'           => 'Argomento:',
				'recent'          => 'Questi articoli esistono già sul sito, quindi scegli un taglio e un titolo diversi:',
				'post_title'      => 'Titolo dell’articolo:',
				'current_post'    => 'Articolo attuale:',
				'editor_context'  => 'Per contesto, ecco l’articolo finora.',
				'editor_title'    => 'Titolo:',
				'editor_format'   => 'Rispondi solo con il contenuto richiesto in Markdown, senza introduzione né note.',
			),
			'pt' => array(
				'default_prompt'  => 'Escreva um artigo de blog útil e envolvente com cerca de 600 palavras sobre: {topic}',
				'default_rewrite' => 'Melhore este artigo: deixe-o mais claro e envolvente, corrija os erros e mantenha o mesmo significado, as mesmas informações e um tamanho semelhante.',
				'title_format'    => "Responda exatamente neste formato e mais nada:\nTITLE: <um título curto e atraente para o artigo>\n\n<o texto do artigo em Markdown, usando ## para os subtítulos>",
				'body_format'     => 'Responda apenas com o texto do artigo em Markdown (use ## para os subtítulos). Não inclua título, introdução nem notas.',
				'topic'           => 'Tema:',
				'recent'          => 'Estes artigos já existem no site, por isso escolha outro ângulo e outro título:',
				'post_title'      => 'Título do artigo:',
				'current_post'    => 'Artigo atual:',
				'editor_context'  => 'Para contexto, este é o artigo até agora.',
				'editor_title'    => 'Título:',
				'editor_format'   => 'Responda apenas com o conteúdo pedido em Markdown, sem introdução nem notas.',
			),
			'nl' => array(
				'default_prompt'  => 'Schrijf een nuttige, boeiende blogpost van ongeveer 600 woorden over: {topic}',
				'default_rewrite' => 'Verbeter deze post: maak hem duidelijker en boeiender, verbeter fouten en houd dezelfde betekenis, dezelfde feiten en ongeveer dezelfde lengte aan.',
				'title_format'    => "Antwoord precies in dit formaat en verder niets:\nTITLE: <een korte, pakkende titel voor de post>\n\n<de tekst van de post in Markdown, met ## voor tussenkoppen>",
				'body_format'     => 'Antwoord alleen met de tekst van de post in Markdown (gebruik ## voor tussenkoppen). Voeg geen titel, inleiding of opmerkingen toe.',
				'topic'           => 'Onderwerp:',
				'recent'          => 'Deze posts staan al op de site, dus kies een andere invalshoek en een andere titel:',
				'post_title'      => 'Titel van de post:',
				'current_post'    => 'Huidige post:',
				'editor_context'  => 'Ter context: dit is de post tot nu toe.',
				'editor_title'    => 'Titel:',
				'editor_format'   => 'Antwoord alleen met de gevraagde inhoud in Markdown, zonder inleiding of opmerkingen.',
			),
		);
	}
}
