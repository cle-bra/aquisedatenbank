<?php
session_start();
session_unset();
session_destroy();
$_SESSION["title"] = "my.KNX-Trainingcenter.com";
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>my.KNX-Trainingcenter.com</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        /* Grundlegendes Styling */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            background-color: #f8f9fa;
            color: #333;
        }

        h1 {
            text-align: center;
            color: #006699;
            margin-bottom: 10px;
        }

        .Kurs {
            text-align: center;
            padding: 20px;
        }

        /* Buttons */
        .button {
            display: inline-block;
            padding: 10px 20px;
            margin: 10px;
            font-size: 16px;
            text-decoration: none;
            background-color: #006699;
            color: white;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .button:hover {
            background-color: #004d66;
        }

        /* Social Media Icons */
        .icon-bar {
            display: flex;
            justify-content: center;
            padding: 10px;
            background-color: #333;
        }

        .icon-bar a {
            color: white;
            font-size: 20px;
            padding: 10px;
            text-decoration: none;
            transition: transform 0.2s ease;
        }

        .icon-bar a:hover {
            transform: scale(1.2);
            color: #00bfff;
        }

        /* Responsive Anpassungen */
        @media (max-width: 768px) {
            h1 {
                font-size: 24px;
            }
            .button {
                display: block;
                margin: 10px auto;
                width: 80%;
            }
        }

        /* Map Styling */
        #map {
            margin: 20px auto;
            text-align: center;
            width: 90%;
            max-width: 800px;
        }

        iframe {
            width: 100%;
            height: 500px;
            border: 0;
        }
    </style>
</head>
<body>

    <!-- Navigation und Titel -->
    <div class="Kurs">
        <h1>Aquise DB</h1>

        <!-- Buttons -->
        <a class="button" href="https://my.knx-trainingcenter.com/informations.php">Informationen anfordern</a>
   <!-- Buttons        <a class="button" href="https://my.knx-trainingcenter.com/form_buchung.php">Buchen</a> -->
        
        <a class="button" href="https://knx-trainingcenter.com/termine/">Kurs Termine</a>
        <a class="button" href="pdf_kursliste_kommende.php" target="_blank">PDF-Liste der kommenden Kurse</a>
        <a class="button" href="https://www.knx.org/knx-de/fuer-fachleute/community/partner/" target="_blank">KNX Partner finden</a>
        <a class="button" href="https://my.knx-trainingcenter.com/user_login.php">User Login</a>
        <a class="button" href="https://my.knx-trainingcenter.com/login.php">Login</a>
    </div>

    <!-- Social Media Links -->
    <div class="icon-bar">
        <a href="https://www.facebook.com/knx.trainingcenter" class="facebook"><i class="fa fa-facebook"></i></a>
        <a href="https://www.google.com/maps?ll=51.214962,6.639242&z=17&t=m&hl=de&gl=DE&mapclient=embed&cid=9782100997697979437" class="google"><i class="fa fa-google"></i></a>
        <a href="https://www.linkedin.com/in/clemens-august-brachtendorf-627a99123/" class="linkedin"><i class="fa fa-linkedin"></i></a>
    </div>


    <div>
        <iframe 
            id="inlineFrameExample"
            title="digitalclock"
            style="width: 100%; height: 400px;"
            src="digitalclock/digitalclock.html">
        </iframe>
    </div>

    <!-- Map Section -->
    <div id="map">
        <h2>CA Brachtendorf GmbH & Co. KG</h2>
        <p>Weiherstrasse 10 - Hinterhof<br>
           40219 Düsseldorf<br>
           F: +49-211-5580527<br>
           M: +49-170-5580527<br>
           E: mail@cab-ih.com
        </p>
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2499.367003737945!2d6.763098815417299!3d51.21231454005692!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47b8ca6cf3f2ca19%3A0x78020f385cc0a580!2sC%20A%20Brachtendorf%20GmbH%20%26%20Co%20KG!5e0!3m2!1sde!2sde!4v1637073230159!5m2!1sde!2sde" 
                allowfullscreen="" loading="lazy">
        </iframe>
    </div>

    <!-- Map Section -->
    <div id="map">
        <h2>KNX-Trainingcenter.com</h2>
        <p>Hanns-Martin-Schleyer Str. 5<br>
           41564 Kaarst<br>
        </p>
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2499.2326234696247!2d6.6365709767015195!3d51.21479023203717!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x47b8b376e40a64f7%3A0x87c1010794c8442d!2sKNX%20Trainingcenter!5e0!3m2!1sde!2sde!4v1734556421575!5m2!1sde!2sde" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">

        </iframe>
    </div>

</body>
</html>
