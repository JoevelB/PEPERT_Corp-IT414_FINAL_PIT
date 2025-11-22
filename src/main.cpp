#include <Arduino.h>
#include <SPI.h>
#include <MFRC522.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <PubSubClient.h> 

// Maoy mag ingon nga ang pin sa reader kay naka connect sa GPIO 5 og 22
#define SS_PIN    5
#define RST_PIN   22

MFRC522 rfid(SS_PIN, RST_PIN);

const char* ssid = "GFiber_ED5A7";
const char* password = "8D442C5D";

const char* serverIP = "192.168.254.103"; 
String serverPath = "/lab2/check_rfid.php";

const char* mqtt_server = "192.168.254.103"; 
WiFiClient espClient;
PubSubClient client(espClient);

// Mo ning mag remember sa last scanned UID
byte lastUID[10];
byte lastUIDSize = 0;
unsigned long lastReadTime = 0;
unsigned long lastStatusTime = 0;

// Ga convert ni siya from raw bytes to string
String uidToString() {
  String uid = "";
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(rfid.uid.uidByte[i], HEX);
    if (i != rfid.uid.size - 1) uid += ":";
  }
  uid.toUpperCase();
  return uid;
}

void printUID() {
  Serial.print(F("UID: "));
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] < 0x10) Serial.print("0");
    Serial.print(rfid.uid.uidByte[i], HEX);
    if (i != rfid.uid.size - 1) Serial.print(":");
  }
  Serial.println();
}

//ga check kung pareha na card ang gina scan
bool isSameCard() {
  if (rfid.uid.size != lastUIDSize) return false;
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] != lastUID[i]) return false;
  }
  return true;
}


void setupWifi() {
  delay(10);
  Serial.println();
  Serial.print("Connecting to ");
  Serial.println(ssid);

  WiFi.begin(ssid, password);

  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print(".");
  }

  Serial.println("");
  Serial.println("WiFi connected");
  Serial.println("IP address: ");
  Serial.println(WiFi.localIP());
}

void reconnect() {
  while (!client.connected()) {
    Serial.print("Attempting MQTT connection...");
    if (client.connect("ESP32_RFID_Sender")) { 
      Serial.println("connected");
    } else {
      Serial.print("failed, rc = ");
      Serial.print(client.state());
      Serial.println(" try again in 2 seconds");
      delay(2000);
    }
  }
}

void setup() {
  Serial.begin(115200);
  SPI.begin();
  rfid.PCD_Init();
  
  setupWifi(); 

  // e initialize mqtt diri
  client.setServer(mqtt_server, 1883);
  Serial.println(F("RFID reader ready. Place your card near the reader..."));
}

void loop() {
  // para ma maintain ang mqtt connection
  if (!client.connected()) {
    reconnect();
  }
  client.loop();

  if (millis() - lastStatusTime > 5000) {
    Serial.println(F("Waiting for RFID card..."));
    lastStatusTime = millis();
  }

  
  if (!rfid.PICC_IsNewCardPresent()) return;
  if (!rfid.PICC_ReadCardSerial()) return;

  String uidStr = uidToString();
  // ignore niya kung pareha ra nga card ang gina scan within 2 seconds
  if (millis() - lastReadTime < 2000 && isSameCard()) {
    rfid.PICC_HaltA();
    rfid.PCD_StopCrypto1();
    return;
  }

  printUID();
  lastUIDSize = rfid.uid.size;
  for (byte i = 0; i < rfid.uid.size; i++) lastUID[i] = rfid.uid.uidByte[i];
  lastReadTime = millis();
  lastStatusTime = millis();

  if (WiFi.status() == WL_CONNECTED) {
    HTTPClient http;
    String url = "http://" + String(serverIP) + serverPath + "?uid=" + uidStr;

    Serial.print("Requesting: ");
    Serial.println(url);

    http.begin(url);
    int httpCode = http.GET();

    if (httpCode > 0) {
      String payload = http.getString();
      Serial.print("Server response: ");
      Serial.println(payload);

      if (payload.startsWith("FOUND|")) {
        int status = payload.substring(6).toInt();
        int displayValue = (status == 0) ? 0 : 1;
        
        Serial.print("RFID FOUND. DB status = ");
        Serial.print(status);
        Serial.print(" -> DISPLAY: ");
        Serial.println(displayValue);

        // send og command sa mqtt broker
        char mqtt_payload[2];
        itoa(displayValue, mqtt_payload, 10);
        client.publish("RFID_LOGIN", mqtt_payload); 
        Serial.print("Published to MQTT: ");
        Serial.println(mqtt_payload);
                
      } else if (payload.startsWith("NOT FOUND")) {
        Serial.println("RFID NOT REGISTERED in DB.");
        
      } 
    } else {
      Serial.print("HTTP Request failed, error: ");
      Serial.println(http.errorToString(httpCode));
    }
    http.end();
  } else {
    Serial.println("WiFi not connected. Retrying...");
    setupWifi(); 
  }

  // stop reading
  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  delay(500);
}