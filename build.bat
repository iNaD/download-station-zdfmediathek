:: Delete old data
del zdfmediathek.host
:: create the .tar.gz
7z a -ttar -so zdfmediathek INFO zdfmediathek.php | 7z a -si -tgzip zdfmediathek.host
