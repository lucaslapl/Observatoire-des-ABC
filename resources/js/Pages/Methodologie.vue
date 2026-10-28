<script setup>
import { Link } from '@inertiajs/vue3';
import Layout from '../Components/Layout.vue';
import Seo from '../Components/Seo.vue';

defineProps({
    chiffres: { type: Object, default: () => ({}) },
});

const fmt = (n) => new Intl.NumberFormat('fr-FR').format(n ?? 0);
</script>

<template>
    <Layout>
        <Seo
            title="Comment les données sont construites"
            description="La méthodologie de l'Observatoire des ABC : collecte des registres publics (OFB, data.gouv.fr, Fonds vert, archives 2022), détection des anomalies, traitement des doublons par fusion sans perte, vérification humaine et journal d'audit."
        />
        <div class="container py-4 doc-content" style="max-width: 760px;">
            <h1 class="h3 mb-4">Comment les données sont construites</h1>

            <p class="lead">
                L'Observatoire suit plus de <strong>{{ fmt(chiffres.projets) }} projets ABC</strong> répartis sur
                {{ fmt(chiffres.communes) }} communes. Ces données ne tombent pas du ciel :
                voici, étape par étape, comment elles sont récupérées, traitées,
                vérifiées et corrigées.
            </p>

            <div class="row text-center my-4">
                <div class="col-4">
                    <div class="h4 mb-0">{{ fmt(chiffres.projets) }}</div>
                    <div class="small text-muted">projets suivis</div>
                </div>
                <div class="col-4">
                    <div class="h4 mb-0">{{ fmt(chiffres.doublons_fusionnes) }}</div>
                    <div class="small text-muted">doublons fusionnés sans perte</div>
                </div>
                <div class="col-4">
                    <div class="h4 mb-0">{{ fmt(chiffres.verifies) }}</div>
                    <div class="small text-muted">projets vérifiés manuellement</div>
                </div>
            </div>

            <h2>1. Trois sources publiques, collectées automatiquement</h2>
            <p>
                Les projets proviennent exclusivement de <strong>registres publics</strong> :
            </p>
            <ul>
                <li>
                    le <strong>registre national des ABC</strong> publié par l'
                    <a href="https://www.ofb.gouv.fr" rel="noopener" target="_blank">Office français de la biodiversité</a>
                    sur <a href="https://data.gouv.fr" rel="noopener" target="_blank">data.gouv.fr</a>
                    — c'est la source officielle, celle qui fait référence en cas de doute ;
                </li>
                <li>
                    l'<strong>archive de l'ancien site abc.naturefrance.fr</strong> (instantané 2022
                    conservé par la <a href="https://web.archive.org" rel="noopener" target="_blank">Wayback Machine</a>),
                    qui couvre des projets antérieurs au registre ;
                </li>
                <li>
                    les listes de projets financés dans le cadre du
                    <a href="https://www.ecologie.gouv.fr/dispositif-fonds-vert" rel="noopener" target="_blank">Fonds vert</a> (mesure « P113 »),
                    qui signalent des ABC financés mais pas encore décrits dans le registre.
                </li>
            </ul>
            <p>
                Un <strong>collecteur automatique</strong> télécharge ces fichiers à intervalle régulier,
                normalise les intitulés (noms de communes, structures porteuses, années) et
                rapproche chaque ligne des projets déjà connus grâce à une clé d'identité
                (nom normalisé + structure porteuse + année de début). Une ligne déjà connue
                met la fiche à jour ; une ligne inconnue crée une nouvelle fiche.
                Des garde-fous refusent les mises à jour suspectes : un fichier corrompu,
                une source qui répond n'importe quoi, ou une disparition massive de projets
                n'est jamais appliquée en silence.
            </p>

            <h2>2. Localisation et détection d'anomalies</h2>
            <p>
                Chaque commune rattachée à un projet est localisée grâce à l'
                <a href="https://geo.api.gouv.fr" rel="noopener" target="_blank">API géographique officielle</a>
                (code INSEE, coordonnées, département, région). Ce géocodage permet de repérer des
                <strong>anomalies</strong> : une commune trop éloignée du reste du projet (probable erreur
                de saisie dans la source), une année de début aberrante, une fiche sans année ou sans
                structure porteuse. Les projets repartis comme suspects alimentent une
                <Link href="/verify">liste de vérification</Link> examinée à la main, projet par projet.
                Cette même API fournit la population (populations légales du recensement
                <a href="https://www.insee.fr" rel="noopener" target="_blank">INSEE</a>) et la superficie
                (IGN, référentiel Admin Express) de chaque
                commune : les fiches affichent la population concernée par un ABC (somme approximative
                pour un périmètre multi-communes, population, superficie et densité pour une commune
                seule). Les communes écartées comme anomalies ne sont pas comptées.
            </p>

            <h2>3. Le travail humain passe avant la machine</h2>
            <p>
                Deux mécanismes complètent la collecte automatique :
            </p>
            <ul>
                <li>
                    <strong>La vérification manuelle</strong> :
                    {{ fmt(chiffres.verifies) }} projets ont été relus et confirmés par un humain,
                    et {{ fmt(chiffres.en_verification) }} sont actuellement en attente de vérification.
                </li>
                <li>
                    <strong>Les contributions du public</strong> : chaque fiche projet permet de signaler
                    une erreur ou proposer une correction (dates, statut, structure porteuse).
                    Une équipe de modération examine chaque proposition avant application.
                </li>
            </ul>
            <p>
                Ces données humaines — vérifications, contributions — <strong>ne sont jamais écrasées</strong>
                par une mise à jour des sources : si un membre a confirmé une date, le prochain collect
                ne la remplacera pas. Chaque correction est enregistrée dans un
                <strong>journal d'audit</strong> qui conserve l'état avant et après modification.
            </p>

            <h2>4. Les doublons : détecter, comprendre, fusionner sans perte</h2>
            <p>
                Un même ABC peut apparaître dans plusieurs sources sous des formes différentes :
                l'ancien site l'écrivait avec une orthographe différente du registre actuel,
                le Fonds vert le liste avec la structure porteuse plutôt que le nom du projet…
                Résultat : parfois <strong>deux fiches pour un seul ABC réel</strong>.
            </p>
            <p>
                Quand un doublon est confirmé, les deux fiches sont <strong>fusionnées</strong> :
                les communes, l'historique d'avancement, les vérifications et les contributions
                des deux fiches sont réunis sur la fiche de référence (celle du registre officiel),
                et l'ancienne page est <strong>redirigée automatiquement et durablement</strong>
                (redirection permanente 301) vers la nouvelle. Rien n'est supprimé :
                la fiche absorbée reste tracée, avec son identifiant et son historique complets.
                À ce jour, {{ fmt(chiffres.doublons_fusionnes) }} doublons ont été ainsi fusionnés.
            </p>
            <p>
                Attention : deux fiches au nom proche ne sont pas toujours un doublon.
                Un même territoire peut porter <strong>plusieurs ABC successifs</strong>
                (« Premier ABC… », « Deuxième ABC… », campagnes 2021 puis 2024…) : ce sont des
                projets distincts, conservés comme tels. Et lorsqu'un doute subsiste, la paire
                est mise en file pour examen humain — jamais fusionnée à l'aveugle.
            </p>

            <h2>5. Corriger une erreur</h2>
            <p>
                Le circuit d'une correction est toujours le même : une anomalie est détectée
                (automatiquement ou signalée par un visiteur), elle est examinée, puis corrigée
                avec journalisation. Les corrections venant des sources officielles sont
                réappliquées à chaque collect ; les corrections humaines validées, elles,
                priment définitivement. Si vous repérez une erreur,
                <Link href="/contact">écrivez-nous</Link> ou proposez une correction depuis la fiche
                du projet concerné.
            </p>

            <h2>6. Le patrimoine écologique : des faits bruts de l'INPN</h2>
            <p>
                Les pages communes affichent les inventaires nationaux du patrimoine naturel
                (<a href="https://inpn.mnhn.fr" rel="noopener" target="_blank">INPN</a>,
                Muséum national d'Histoire naturelle) qui touchent le territoire :
                <strong>ZNIEFF</strong> (zones naturelles d'intérêt écologique, faunistique et
                floristique), <strong>sites Natura 2000</strong> et <strong>aires protégées</strong>
                (réserves, arrêtés de protection de biotope, sites classés…). La date de
                référence de chaque base est indiquée sur la page concernée, car ces
                référentiels sont actualisés périodiquement et nos données datent de
                l'extraction importée.
            </p>
            <p>
                Nous affichons ces informations <strong>telles quelles</strong> : des zonages,
                des surfaces et des comptages d'espèces déterminantes ou d'habitats, jamais un
                score ou un indicateur composite qui n'aurait pas de valeur scientifique. Les
                associations zonage ↔ commune de ZNIEFF et Natura 2000 sont celles des
                référentiels officiels de l'INPN ; pour les aires protégées, qui ne sont
                publiées que sous forme géographique, le recouvrement avec les communes est
                calculé au moment de l'import à l'aide du découpage administratif
                <a href="https://geoservices.ign.fr/adminexpress" rel="noopener" target="_blank">Admin Express</a>
                (IGN) : c'est une approche à l'échelle communale, les surfaces indiquées sont
                des ordres de grandeur. Les espèces dites « confidentielles » dans le
                référentiel ZNIEFF ne sont jamais affichées. Sources réutilisées sous
                <a href="https://inpn.mnhn.fr/legal" rel="noopener" target="_blank">les licences de l'INPN</a>
                et la Licence Ouverte (IGN).
            </p>
            <p>
                Les fiches ZNIEFF et Natura 2000 ne sont publiées que pour les zonages touchant
                au moins une commune ayant un ABC : cet Observatoire ne reflète pas l'ensemble
                de l'inventaire national, il le met en regard des Atlas de la Biodiversité
                Communale — pour le reste, l'INPN reste la source de référence. Enfin, les
                <strong>documents d'objectifs</strong> (DOCOB) des sites Natura 2000 sont
                hébergés sur ce site tels que transmis dans la base INPN de décembre 2024 :
                chaque document est celui en vigueur à cette date et peut depuis avoir été
                révisé.
            </p>

            <h2>7. Tout est consultable</h2>
            <p>
                L'intégralité des données est exportable librement en
                <a href="/api/abc.geojson">GeoJSON</a>
                (Licence Ouverte : réutilisation libre, y compris commerciale,
                sous réserve de mentionner les sources).
                La <Link href="/verify">liste de vérification</Link> publique montre quels projets
                restent à examiner : vous voyez donc, en temps réel, ce qui est sûr, ce qui est
                en cours de vérification, et ce que le site ne sait pas encore.
            </p>
        </div>
    </Layout>
</template>
