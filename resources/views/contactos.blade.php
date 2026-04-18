<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contactos...</title>
</head>
<body>
    <h1>fernando.bento@islasantarem.pt</h1>
    <h1>Telefone : 243 305 880, MVC</h1>
    <form action="{{ route('acercade.pagina') }}" method="GET">
        <label for="VIdade">Introduza a idade:</label>
        <input type="number" name="VIdade" id="VIdade" min="0" required>
        <button type="submit">Ir para Acerca de</button>
    </form>
</body>
</html>
