#include <Arduino.h>
#include <SPI.h>
#include <MFRC522.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <PubSubClient.h> // REQUIRED: Install "PubSubClient" by Nick O'Leary

#define SS_PIN    5
#define RST_PIN   22
#define LED_PIN   2

MFRC522 rfid(SS_PIN, RST_PIN);

// --- WIFI SETTINGS ---
const char* known_ssids[] = { "GFiber_ED5A7" };
const char* known_passwords[] = { "8D442C5D" };
const int num_known_networks = sizeof(known_ssids) / sizeof(known_ssids[0]);

// --- SERVER / DATABASE SETTINGS ---
const char* serverIP = "192.168.254.103"; // IP for XAMPP (PHP)
String serverPath = "/lab2/check_rfid.php";

// --- MQTT SETTINGS (NEW) ---
const char* mqtt_server = "192.168.254.103"; // IP of Laptop with Mosquitto/MQTTX
WiFiClient espClient;
PubSubClient client(espClient);

byte lastUID[10];
byte lastUIDSize = 0;
unsigned long lastReadTime = 0;
unsigned long lastStatusTime = 0;

void blinkLED(int times, int delayMs) {
  for (int i = 0; i < times; i++) {
    digitalWrite(LED_PIN, HIGH);
    delay(delayMs);
    digitalWrite(LED_PIN, LOW);
    delay(delayMs);
  }
}

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

bool isSameCard() {
  if (rfid.uid.size != lastUIDSize) return false;
  for (byte i = 0; i < rfid.uid.size; i++) {
    if (rfid.uid.uidByte[i] != lastUID[i]) return false;
  }
  return true;
}

void connectToFastestWiFi() {
  Serial.println(F("Scanning for WiFi..."));
  int n = WiFi.scanNetworks();
  if (n == 0) {
    Serial.println(F("No WiFi networks found."));
    return;
  }

  int best_network_index = -1;
  long best_rssi = -200;
  for (int i = 0; i < n; i++) {
    String scanned_ssid = WiFi.SSID(i);
    long scanned_rssi = WiFi.RSSI(i);
    for (int j = 0; j < num_known_networks; j++) {
      if (scanned_ssid.equals(known_ssids[j])) {
        if (scanned_rssi > best_rssi) {
          best_rssi = scanned_rssi;
          best_network_index = j;
        }
      }
    }
  }

  if (best_network_index != -1) {
    WiFi.begin(known_ssids[best_network_index], known_passwords[best_network_index]);
    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 20) {
      delay(500);
      Serial.print(".");
      attempts++;
    }
    Serial.println();
    if (WiFi.status() == WL_CONNECTED) {
      Serial.print(F("Connected to "));
      Serial.print(WiFi.SSID());
      Serial.print(" | IP: ");
      Serial.println(WiFi.localIP());
    } else {
      Serial.println(F("Failed to connect to WiFi."));
    }
  } else {
    Serial.println(F("No known WiFi networks available."));
  }
}

// --- NEW FUNCTION: MQTT RECONNECT ---
void reconnect() {
  // Loop until we're reconnected
  while (!client.connected()) {
    Serial.print("Attempting MQTT connection...");
    // Attempt to connect
    if (client.connect("ESP32_RFID_Sender")) { 
      Serial.println("connected");
    } else {
      Serial.print("failed, rc=");
      Serial.print(client.state());
      Serial.println(" try again in 2 seconds");
      delay(2000);
    }
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(LED_PIN, OUTPUT);
  digitalWrite(LED_PIN, LOW);
  SPI.begin();
  rfid.PCD_Init();
  
  connectToFastestWiFi();

  // Setup MQTT Server
  client.setServer(mqtt_server, 1883);
  
  Serial.println(F("RFID reader ready. Place your card near the reader..."));
}

void loop() {
  // Ensure MQTT is connected
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

      // Logic to handle Server Response and Send to MQTT
      if (payload.startsWith("FOUND|")) {
        int status = payload.substring(6).toInt();
        int displayValue = (status == 0) ? 0 : 1;
        
        Serial.print("RFID FOUND. DB status=");
        Serial.print(status);
        Serial.print(" -> DISPLAY: ");
        Serial.println(displayValue);

        // --- SEND TO MQTT (The "1" or "0") ---
        // This fulfills the requirement to send 1/0 to MQTTX and Relay ESP
        char mqtt_payload[2];
        itoa(displayValue, mqtt_payload, 10);
        client.publish("RFID_LOGIN", mqtt_payload); 
        Serial.print("Published to MQTT: ");
        Serial.println(mqtt_payload);
        // -------------------------------------

        blinkLED(2, 150);
      } else if (payload.startsWith("NOTFOUND")) {
        Serial.println("RFID NOT REGISTERED in DB.");
        // Optional: Publish "NOTFOUND" or "-1" if you want, 
        // but instructions only specified 1 and 0.
        blinkLED(3, 100);
      } else {
        Serial.println("Unexpected response: " + payload);
      }
    } else {
      Serial.print("HTTP Request failed, error: ");
      Serial.println(http.errorToString(httpCode));
    }
    http.end();
  } else {
    Serial.println("WiFi not connected. Retrying...");
    connectToFastestWiFi();
  }

  rfid.PICC_HaltA();
  rfid.PCD_StopCrypto1();
  delay(500);
}