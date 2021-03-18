{literal}
<!doctype html>
  <html>
    <head>
      <link rel="stylesheet" href="node_modules/xterm/dist/xterm.css" />
      <script src="node_modules/xterm/dist/xterm.js"></script>
      <script src="node_modules/xterm/dist/addons/attach/attach.js"></script>
      <script src="node_modules/xterm/dist/addons/fit/fit.js"></script>
    </head>
    <body>
      <div id="terminal" style="width:100%; height:90vh"></div>
      <script>
        var resizeInterval;
        var ws_closed = false;
        var wSocket = new WebSocket("wss:{/literal}{$server_name}{literal}/wss/");
        Terminal.applyAddon(attach);  // Apply the `attach` addon
        Terminal.applyAddon(fit);  //Apply the `fit` addon
        var term = new Terminal();
        term.open(document.getElementById('terminal'));

        function ConnectServer(){
          if (ws_closed) {
            location.reload();
          }

          var dataSend = {"auth":
                            {
{/literal}
                            "idconnection":"{$idconnection}"
{literal}
                            }
                          };
          wSocket.send(JSON.stringify(dataSend));
          console.log("Connected");
//          term.fit();
          term.focus();
        }       

        wSocket.onopen = function (event) {
          console.log("Socket Open");
          term.attach(wSocket,false,false);
          window.setInterval(function(){
            wSocket.send(JSON.stringify({"refresh":""}));
          }, 700);
        };

        wSocket.onerror = function (event){
          term.detach(wSocket);
          alert("Connection Closed");
        }        

        wSocket.onclose = function (event) {
          console.log("Socket Close");
          ws_closed = true;
        }

        term.on('data', function (data) {
          var dataSend = {"data":{"data":data}};
          wSocket.send(JSON.stringify(dataSend));
          //Xtermjs with attach dont print zero, so i force. Need to fix it :(
          if (data=="0"){
            term.write(data);
          }
        })
        
        //Execute resize with a timeout
        window.onresize = function() {
          clearTimeout(resizeInterval);
          resizeInterval = setTimeout(resize, 400);
        }
        // Recalculates the terminal Columns / Rows and sends new size to SSH server + xtermjs
        function resize() {
          if (term) {
//            term.fit()
          }
        }

        setTimeout(
            function () {
                ConnectServer();
            },
            "1000"
        );
      </script>
    </body>
  </html>
{/literal}
