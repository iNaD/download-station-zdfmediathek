:: Delete old data
del zdfmediathek.host

:: get recent version of the provider base class
copy /Y ..\provider-boilerplate\src\provider.php provider.php

:: create the .tar.gz
7z a -ttar -so zdfmediathek INFO zdfmediathek.php provider.php | 7z a -si -tgzip zdfmediathek.host

del provider.php