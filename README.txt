leame: 

0. URL Github: https://github.com/adrianam3/8S-g11-pi-pqrs.git
1. descaragar el archivo comprimido 8S-g11-pi-pqrs.zip 
2. Para que se ejecute el backend se require tener instalado XAMPP
3. Descomprimr en la carpeta htdocs de xammp, la ruta por defecto es C:\xampp\htdocs
4. Se suguiere utilizar visual studio code para abri la carpeta principal del proyecto que es "8S-g11-pi-pqrs"
5. Para ejecutar el backend debe estar ejecutandose en xammp el servidor web Apache y MySQL
6. Crear la base de datos help_desk en MySQL, puede usar phpmyadmin
7. Importar la estructura de a base de datos con el script que se ecuentra en C:\xampp\htdocs\8S-g11-pi-pqrs\8s-pqrs-backend\public\imbauto3_pqrs.sql
9. Modificar las credenciales de MySQL en C:\xampp\htdocs\helpdesk-g11-pi\hd_backend\config\config.php
	private $host = "localhost";
    private $usuario = "root";
    private $pass = "clave";
    private $base = "imbauto3_pqrs";
10. Para ejecutar el frontend, se debe ejecutar una terminal e ingresar a la ruta C:\xampp\htdocs\8S-g11-pi-pqrs\8s-pqrs-backend\public\db\imbauto3_pqrs.sql
11. ejecutar el comando npm install, para que se descaren todas la dependecias configuradas en el proyecto
12. ejecutar el siguiente comando npm install ngx-quill@latest npm install quill@latest
13. ejecutar el comando npm star, para iniciar la aplicación 
14. Usuarios aplicación Web Help Desk: 

	administrador: 
	email: adrian.merlo.am3+1@gmail.com
	clave: pass1234A.a

	coordinador:
	Usuario: adrian.merlo.am3+20@gmail.com
	Contraseña: pass1234A.a


	jefe o gerente: 
	adrian.merlo.am3+21@gmail.com
	clave: pass1234A.a


	usuario: 
	adrian.merlo.am3+25@gmail.com
	clave: pass1234A.a


