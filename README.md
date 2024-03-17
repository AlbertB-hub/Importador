Importador de tableros de Monday con colas para guardar los archivos adjuntos a cada item.

Es un importador que se puede utilizar para importar tableros de Monday directamente a tablas de SQL y colas a colecciones de MongoDB tipo GridFS.
El importador crea los nuevos items, pero si estos ya existieran en la base de datos(según el Id de Monday), este registro se actualiza. En caso
de dejar de existir en el tablero de Monday, se realiza un soft-delete.

En el YAML viene la configuración necesaria para organizar la respuesta de Monday y guardar los datos como se necesite. Se pueden configurar los tipos
de los campos y las relaciones entre las diferentes clases.

Al ejecutar el comando se realiza una llamada a la API de Monday usando GraphQL para recibir un JSON con todos los items y subitems para importar.

En jsonviewer.json se puede ver un ejemplo de la respuesta que devuelve Monday de un solo item.

Esa información pasa por el denormalizer que se encarga de convertir todo ese json en objetos según la configuración del config monday.yaml de ejemplo.
Ahí están todas las configuraciones de tipos de las diferentes columnas. En el denormalizador también se crea un array con los ids de los archivos 
adjuntos necesarios para las colas, así como guardar los ids de los items para comprobar más adelante cuales se han borrado de Monday y cuales no.

Una vez creados los objetos, en el comando se procede a crear las relaciones entre ellos, crear las colas para guardar los archivos adjuntos (en este
caso en colecciones GridFS de MongoDB), y realizar una soft-delete de los items de Monday que se hayan borrado de su tablero correspondiente.
