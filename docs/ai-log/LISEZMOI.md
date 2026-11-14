# Traces d'utilisation de l'IA

## Outils utilisés

Les outils utilisés sont **GitHub Copilot** et **Agy**. Ils ont été utilisés principalement pour
la production de code, la recherche dans le dépôt, la rédaction de tests, le débogage et la
documentation technique.

La conception fonctionnelle et les grands choix d'architecture ont été définis par moi-même. Les
échanges avec l'IA m'ont servi à clarifier les contraintes, comparer les options et obtenir une
vue à 360 degrés d'une fonctionnalité avant de demander son implémentation.

Ma méthode de travail a généralement été la suivante :

1. Comprendre le besoin et discuter les cas limites avec l'IA.
2. Trancher moi-même les choix de conception et d'architecture.
3. Confier à l'IA l'implémentation ciblée.
4. Relire le code, exécuter les tests et vérifier le comportement réel.
5. Corriger, simplifier et itérer lorsque le résultat ne correspondait pas au besoin.

## Contenu de l'archive

- Les notes de cadrage et de conception des sessions.
- Les journaux de travail sur les règles métier, les tests, le design et le moteur 3D.
- Les transcriptions des échanges et des étapes d'implémentation.
- Le présent fichier, qui explique la méthode et les enseignements tirés.

Les fichiers ont été nettoyés avant archivage afin de retirer les mots de passe, clés, tokens,
identifiants de service et autres secrets. Les fichiers de configuration réels ne font pas partie
de l'archive.

## Trois enseignements

### 1. Où l'IA m'a fait gagner du temps

L'IA m'a fait gagner du temps sur l'exploration du code, la génération de structures répétitives,
la rédaction de tests et le débogage. Elle a aussi accéléré la comparaison de plusieurs solutions
pour des sujets comme l'outbox transactionnel, la synchronisation Google Sheets, la configuration
Docker et l'installation sur une machine propre.

### 2. Où l'IA s'est trompée

L'IA a parfois considéré une fonctionnalité comme terminée trop tôt. Elle a aussi proposé ou
conservé des instructions redondantes dans la documentation, et le premier flux d'installation
oubliant l'installation de `vendor/` ne fonctionnait pas depuis un checkout réellement propre.
Dans l'intégration Google Sheets, certains détails de payload et de permissions ont également dû
être corrigés après un test réel avec l'API.

### 3. Comment je l'ai repéré

Je n'ai pas accepté les résultats uniquement sur la base de la réponse de l'IA. J'ai vérifié le
diff, exécuté les tests PHPUnit, validé le schéma Doctrine, lancé les commandes Docker et testé
la synchronisation avec Google Sheets. Le test d'installation depuis une copie propre du dépôt a
révélé l'oubli de Composer, puis la correction a été ajoutée et vérifiée. Cette boucle de
vérification, d'erreur et d'itération a été utilisée pour les fonctionnalités importantes.
