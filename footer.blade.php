<div>
    <div id="boxdiv"><span id="errorarea"></span></div>
    <p>
        <select name="CertListBox" id="CertListBox"></select>
    </p>

    <div id="cert_info" style="display:none">
        <h2>Информация о сертификате</h2>
        <p class="info_field" id="subject"></p>
        <p class="info_field" id="issuer"></p>
        <p class="info_field" id="from"></p>
        <p class="info_field" id="till"></p>
        <p class="info_field" id="provname"></p>
        <p class="info_field" id="privateKeyLink"></p>
        <p class="info_field" id="algorithm"></p>
        <p class="info_field" id="status"></p>
        <p class="info_field" id="location"></p>
    </div>
    <div>
        <p>
            <a href="/testpdf.pdf" download="">pdf скачать</a>
            <a href="/testpdf.pdf" class="js-file-sign">pdf подписать</a>
        </p>
        <p>
            <a href="/testtxt.txt" download="">testtxt скачать</a>
            <a href="/testtxt.txt" class="js-file-sign">testtxt подписать</a>
        </p>
    </div>
    <p id="signature"></p>
</div>

<script type="application/javascript" src="/assets/app/js/cadesplugin_api.js"></script>
<script type="application/javascript" src="/assets/app/js/cadescode.js"></script>
<script type="application/javascript" src="/assets/app/js/async_cadescode.js"></script>
<script type="application/javascript">
    //base64 to file
    function dataURItoBlob(dataURI,filename,_mime) {
        const byteString = window.atob(dataURI);
        const arrayBuffer = new ArrayBuffer(byteString.length);
        const int8Array = new Uint8Array(arrayBuffer);
        for (let i = 0; i < byteString.length; i++) {
            int8Array[i] = byteString.charCodeAt(i);
        }
        const mime = _mime || 'application/pdf';
        const blob = new Blob([int8Array], { type: mime});
        const url = URL.createObjectURL(blob);
        const downloadLink = document.createElement("a");
        const fileName = filename || "abc.pdf";
        downloadLink.href = url;
        downloadLink.download = fileName;
        downloadLink.click();
    }


    var CADESCOM_CADES_BES = 1;
    var CAPICOM_CURRENT_USER_STORE = 2;
    var CAPICOM_MY_STORE = "My";
    var CAPICOM_STORE_OPEN_MAXIMUM_ALLOWED = 2;
    var CAPICOM_CERTIFICATE_FIND_SUBJECT_NAME = 1;
    var CADESCOM_BASE64_TO_BINARY = 1;
    let error = false;

    //select
    setTimeout(()=>{
        try{
            FillCertList_Async('CertListBox');
        }catch(ex){
            alert('Error loading certificates');
            error = true;
        }
    },2000);


    //обработчик события клика для подписи выбранного файла
    function runDoc(e) {
        e.preventDefault();
        cadesplugin.async_spawn(function* (args) {
            // Проверяем, работает ли File API
            if (window.FileReader) {
                // Браузер поддерживает File API.
            } else {
                alert('The File APIs are not fully supported in this browser.');
            }


            //url файла, который нужно подписать
            let url = e.target.getAttribute('href') || '';
            if(url){
                fetch(url)
                    .then(res => res.blob()) // Gets the response and returns it as a blob
                    .then(blob => {
                        var oFReader = new FileReader();
                        if (typeof (oFReader.readAsDataURL) != "function") {
                            alert("Method readAsDataURL() is not supported in FileReader.");
                            return;
                        }

                        //base64 подписываемого файла
                        oFReader.readAsDataURL(blob);
                        oFReader.onload = function (oFREvent) {
                            cadesplugin.async_spawn(function* (args) {
                                var header = ";base64,";
                                var sFileData = oFREvent.target.result;
                                var sBase64Data = sFileData.substr(sFileData.indexOf(header) + header.length);

                                //sCertName - выбранный сертификат
                                var CertListBox = document.getElementById('CertListBox');
                                var sCertNameText = CertListBox.options[CertListBox.selectedIndex].text;
                                var sCertName = sCertNameText.match(/CN=([^;]+)/i);// Здесь следует заполнить SubjectName сертификата
                                sCertName = typeof sCertName[1] != 'undefined'? sCertName[1] : '';
                                if ("" == sCertName) {
                                    alert("Введите имя сертификата (CN).");
                                    return;
                                }

                                //открывается хранилище сертификатов CAdESCOM.Store
                                var oStore = yield cadesplugin.CreateObjectAsync("CAdESCOM.Store");
                                yield oStore.Open(CAPICOM_CURRENT_USER_STORE, CAPICOM_MY_STORE,
                                    CAPICOM_STORE_OPEN_MAXIMUM_ALLOWED);

                                //поиск выбранного сертификата sCertName в хранилище
                                var oStoreCerts = yield oStore.Certificates;
                                var oCertificates = yield oStoreCerts.Find(
                                    CAPICOM_CERTIFICATE_FIND_SUBJECT_NAME, sCertName);
                                var certsCount = yield oCertificates.Count;
                                if (certsCount === 0) {
                                    alert("Certificate not found: " + sCertName);
                                    return;
                                }


                                //выборка объекта CAdESCOM.CPSigner для подписи и настройка подписи
                                //https://docs.cryptopro.ru/cades/reference/cadescom/cadescom_class/cpsigner?id=%d0%9e%d0%b1%d1%8a%d0%b5%d0%ba%d1%82-cpsigner
                                var oCertificate = yield oCertificates.Item(1);
                                var oSigner = yield cadesplugin.CreateObjectAsync("CAdESCOM.CPSigner");
                                yield oSigner.propset_Certificate(oCertificate);
                                yield oSigner.propset_CheckCertificate(true);

                                var oSignedData = yield cadesplugin.CreateObjectAsync("CAdESCOM.CadesSignedData");
                                yield oSignedData.propset_ContentEncoding(CADESCOM_BASE64_TO_BINARY);
                                yield oSignedData.propset_Content(sBase64Data);

                                //подпись файла
                                //Добавляет к файлу усовершенствованную подпись, которая сохраняется в переменной sSignedMessage.
                                try {
                                    var sSignedMessage = yield oSignedData.SignCades(oSigner, CADESCOM_CADES_BES, true);
                                } catch (err) {
                                    alert("Failed to create signature. Error: " + cadesplugin.getLastError(err));
                                    return;
                                }
                                yield oStore.Close();

                                // Выводим отделенную подпись в BASE64 на страницу
                                // Такая подпись должна проверяться в КриптоАРМ и cryptcp.exe
                                document.getElementById("signature").innerHTML = sSignedMessage;
                                verifySign(sBase64Data, sSignedMessage, true);
                            });
                        };
                    });
            }
        });
        return false;
    }


    //Проверка подписи
    //sBase64Data - base64 файла, у которого проверяется подпись
    //sSignedMessage - отсоединённая подпись
    function verifySign(sBase64Data, sSignedMessage, save=false, filename='sign.sig'){
        if(!sBase64Data || !sSignedMessage){
            alert('Failed to verify signature');
            return;
        }

        cadesplugin.async_spawn(function* (args) {
            //Проверяет полученную подпись
            var oSignedData = yield cadesplugin.CreateObjectAsync("CAdESCOM.CadesSignedData");
            try {
                yield oSignedData.propset_ContentEncoding(CADESCOM_BASE64_TO_BINARY);
                yield oSignedData.propset_Content(sBase64Data);
                yield oSignedData.VerifyCades(sSignedMessage, CADESCOM_CADES_BES, true);
                alert("Signature verified");
                if(save){
                    //сохраняет подпись в файл, чтобы можно было проверить подпись файла
                    dataURItoBlob(sSignedMessage, filename, 'application/pgp-signature');
                    // dataURItoBlob(sSignedMessage, filename, 'text/plain');
                }
            } catch (err) {
                alert("Failed to verify signature. Error: " + cadesplugin.getLastError(err));
                return;
            }
        })
    }


    if(!error){
        document.querySelectorAll('.js-file-sign').forEach(item => {
            item.addEventListener('click', runDoc);
        });
    }

</script>